<?php

use App\Contract\Billing\ReconcilerContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\CancellationFailed;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 8's table: "Cancelling | Us ✅ | Dodo ✅ (both work)". This is our
 * half, and until now it did not exist - cancelling moved our records, dropped
 * the workspace to free, and left Dodo charging the card every month.
 *
 * A customer in that state loses the paid product and keeps paying for it,
 * finds out from a bank statement rather than from us, and - because Dodo is
 * merchant of record - their recourse is a formal chargeback. Section 16 names
 * that as the risk this whole area is designed around.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    PlanPrice::all()->each(fn ($p) => $p->update(['dodo_product_id' => 'prod_'.$p->id]));

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();
});

function payingWorkspace(): void
{
    subscriptions()->grantPlan(
        test()->workspace, test()->price, AdminUser::factory()->create(), 'seed'
    );

    test()->workspace->fresh()->subscription->update([
        'dodo_subscription_id' => 'sub_dodo_1',
        'status' => SubscriptionStatus::Active,
    ]);
}

it('stops the charging when a customer cancels', function () {
    $gateway = fakeGateway();
    payingWorkspace();

    subscriptions()->cancel($this->workspace->fresh());

    expect($gateway->cancellations)->toHaveCount(1)
        ->and($gateway->cancellations[0]['subscription'])->toBe('sub_dodo_1');

    // Section 6: dropped to free, and nothing deleted. Queried off the model
    // rather than the relation, which only ever returns a LIVE subscription -
    // a cancelled one is excluded by design.
    $fresh = $this->workspace->fresh();
    $subscription = Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $fresh->id)->firstOrFail();

    expect($fresh->billing_status)->toBe(BillingStatus::Unpaid)
        ->and($subscription->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subscription->ended_at)->not->toBeNull()
        ->and($fresh->canRead())->toBeTrue();
});

/*
 * Immediate, not scheduled: the lines above take the paid features away now, so
 * scheduling the money to stop at the period end would charge them again for a
 * plan they no longer have.
 */
it('cancels immediately rather than at the next billing date', function () {
    $gateway = fakeGateway();
    payingWorkspace();

    subscriptions()->cancel($this->workspace->fresh());

    expect($gateway->cancellations[0]['at_period_end'])->toBeFalse();
});

/*
 * The order that matters. If Dodo cannot be reached, nothing moves - the
 * customer keeps the plan they are paying for and can try again. A cancellation
 * that succeeded on our side alone is the one failure that is not recoverable
 * by retrying, because they no longer know anything is wrong.
 */
it('changes nothing when the provider cannot be reached', function () {
    fakeGateway()->broken();
    payingWorkspace();

    expect(fn () => subscriptions()->cancel($this->workspace->fresh()))
        ->toThrow(CancellationFailed::class);

    $fresh = $this->workspace->fresh();
    expect($fresh->billing_status)->toBe(BillingStatus::Active)
        ->and($fresh->subscription->status)->toBe(SubscriptionStatus::Active);
});

/*
 * Section 14 phase 3: a plan granted by hand has no payment account behind it.
 * There is nothing to stop charging, and trying would fail on a subscription
 * that was never theirs.
 */
it('cancels a comped plan without calling the provider', function () {
    $gateway = fakeGateway();

    subscriptions()->grantPlan(
        $this->workspace, $this->price, AdminUser::factory()->create(), 'comp'
    );

    subscriptions()->cancel($this->workspace->fresh());

    expect($gateway->cancellations)->toBeEmpty()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);
});

// A free workspace has no subscription at all; cancelling is a no-op, not a crash.
it('survives cancelling a workspace that never paid', function () {
    $gateway = fakeGateway();

    subscriptions()->cancel($this->workspace);

    expect($gateway->cancellations)->toBeEmpty()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);
});

/*
 * Section 16: "A customer cancels on the provider's page. Our records find out
 * via notification." That inbound path must not call back OUT to Dodo - they
 * already did it, and an app that answers every cancellation webhook with a
 * cancellation request is one bad response away from a loop.
 */
it('does not call back out when the cancellation came from Dodo', function () {
    $gateway = fakeGateway();
    payingWorkspace();

    $event = WebhookEvent::create([
        'provider' => 'dodo',
        'event_id' => 'msg_cancel_1',
        'event_type' => 'subscription.cancelled',
        'payload' => ['data' => [
            'subscription_id' => 'sub_dodo_1',
            'cancelled_at' => now()->toIso8601String(),
        ]],
        'signature_verified' => true,
        'occurred_at' => now(),
        'received_at' => now(),
        'attempts' => 0,
    ]);

    app(ReconcilerContract::class)->reconcile($event);

    // Our state moved; nothing went back to them.
    expect($gateway->cancellations)->toBeEmpty()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);

    $subscription = Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->id)->firstOrFail();

    expect($subscription->status)->toBe(SubscriptionStatus::Canceled);
});

// The customer-facing route, end to end.
it('lets an owner cancel from the billing page', function () {
    $gateway = fakeGateway();
    payingWorkspace();

    $this->actingAs($this->owner)
        ->delete(route('billing.cancel'))
        ->assertRedirect();

    expect($gateway->cancellations)->toHaveCount(1);
});

// Never a stack trace: a provider outage has to read as a sentence the customer
// can act on, with their plan visibly untouched.
it('tells the customer plainly when it could not be done', function () {
    fakeGateway()->broken();
    payingWorkspace();

    $this->actingAs($this->owner)
        ->from(route('billing.index'))
        ->delete(route('billing.cancel'))
        ->assertRedirect(route('billing.index'))
        ->assertSessionHasErrors('errors');

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active);
});
