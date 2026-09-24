<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingStatus;
use App\Enums\CancellationFeedback;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 15 asks for "monthly churn, and how much of it is voluntary versus
 * failed payments" - and the voluntary half is only useful if you know what it
 * was FOR. Leaving over price and leaving over a missing feature want opposite
 * responses, and one "cancelled" count cannot tell them apart.
 *
 * The rule that shapes all of this: `routes/web/billing.php` commits to never
 * blocking the exit. Every field here is optional, forever - a question you
 * must answer in order to leave is a blocked exit, and with a merchant of
 * record an obstructed cancellation becomes a chargeback rather than a
 * retained customer.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->gateway = fakeGateway();

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();
    $this->price->update(['dodo_product_id' => 'prod_pro']);

    app(SubscriptionContract::class)
        ->grantPlan($this->workspace, $this->price, AdminUser::factory()->create(), 'seed');

    // Held with the provider, so cancelling actually reaches them.
    Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->id)
        ->update(['dodo_subscription_id' => 'sub_1']);
});

it('records why they left, on our side and theirs', function () {
    $this->actingAs($this->owner)
        ->delete(route('billing.cancel'), [
            'feedback' => 'too_expensive',
            'comment' => 'Cheaper elsewhere for what we use.',
        ])
        ->assertRedirect();

    $subscription = $this->workspace->subscriptions()->withoutWorkspaceScope()->first();

    // Ours, because section 15's churn split is our metric and asking Dodo for
    // it would put a rate-limited API call behind a dashboard.
    expect($subscription->cancellation_feedback)->toBe(CancellationFeedback::TooExpensive)
        ->and($subscription->cancellation_comment)->toBe('Cheaper elsewhere for what we use.')
        // And theirs, so the two records agree.
        ->and($this->gateway->cancellations[0]['feedback'])->toBe('too_expensive')
        ->and($this->gateway->cancellations[0]['comment'])->toBe('Cheaper elsewhere for what we use.');
});

/*
 * The load-bearing test. Cancelling with no answer at all has to work exactly
 * as it did before any of this existed.
 */
it('never makes anyone answer to leave', function () {
    $this->actingAs($this->owner)
        ->delete(route('billing.cancel'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $subscription = $this->workspace->subscriptions()->withoutWorkspaceScope()->first();

    // Scheduled for the period end: still paid up, so still working until then.
    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        ->and($subscription->cancel_at_period_end)->toBeTrue()
        ->and($subscription->cancellation_feedback)->toBeNull()
        ->and($this->gateway->cancellations)->toHaveCount(1);
});

it('accepts a reason with no comment, and a comment with no reason', function (array $payload) {
    $this->actingAs($this->owner)
        ->delete(route('billing.cancel'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $subscription = $this->workspace->subscriptions()->withoutWorkspaceScope()->first();

    expect($subscription->cancel_at_period_end)->toBeTrue()
        ->and($this->gateway->cancellations)->toHaveCount(1);
})->with([
    'reason only' => [['feedback' => 'unused']],
    'comment only' => [['comment' => 'Just trying it out.']],
]);

// A value we do not recognise is a bug in the caller, not a churn reason.
it('refuses a reason that is not one of ours', function () {
    $this->actingAs($this->owner)
        ->delete(route('billing.cancel'), ['feedback' => 'they_were_rude'])
        ->assertSessionHasErrors('feedback');

    // And nothing was cancelled on a refused request.
    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        ->and($this->gateway->cancellations)->toBeEmpty();
});

// Nobody gets to use the cancel endpoint as free storage.
it('refuses a comment longer than we will store', function () {
    $this->actingAs($this->owner)
        ->delete(route('billing.cancel'), ['comment' => str_repeat('x', 1001)])
        ->assertSessionHasErrors('comment');

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active);
});

// Section 3: admins manage people, never billing - including the exit.
it('still refuses someone who may not manage billing', function () {
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    App\Models\WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();

    $this->actingAs($admin)
        ->delete(route('billing.cancel'), ['feedback' => 'unused'])
        ->assertForbidden();

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active);
});
