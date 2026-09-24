<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Notifications\Billing\SubscriptionCanceledNotification;
use App\Notifications\Billing\TrialEndingNotification;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * The customer's cancel button ends the subscription at the end of the period
 * they have already paid for. Cancelling immediately took an annual customer's
 * remaining months away with no refund, which with a merchant of record ends
 * as a chargeback.
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

    // A live subscription held with Dodo, paid through a month from now.
    $this->heldWithProvider = function (array $attributes = []): Subscription {
        subscriptions()->grantPlan($this->workspace, $this->price, AdminUser::factory()->create(), 'seed');

        $subscription = Subscription::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)->live()->firstOrFail();

        $subscription->update(array_merge([
            'dodo_subscription_id' => 'sub_dodo_1',
            'status' => SubscriptionStatus::Active,
            'current_period_end' => now()->addMonth(),
        ], $attributes));

        return $subscription->fresh();
    };
});

it('schedules the cancellation for the end of the paid period', function () {
    $gateway = fakeGateway();
    ($this->heldWithProvider)();

    $this->actingAs($this->owner)
        ->delete(route('billing.cancel'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $subscription = Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->id)->firstOrFail();

    expect($gateway->cancellations)->toHaveCount(1)
        ->and($gateway->cancellations[0]['at_period_end'])->toBeTrue()
        // Still paid up, so still working.
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->cancel_at_period_end)->toBeTrue()
        // Not churned yet: churn counts canceled_at, and they may still resume.
        ->and($subscription->canceled_at)->toBeNull()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        ->and($this->workspace->fresh()->canWrite())->toBeTrue();
});

it('emails the owner a confirmation naming the end date', function () {
    Notification::fake();
    fakeGateway();
    ($this->heldWithProvider)();

    $this->actingAs($this->owner)->delete(route('billing.cancel'));

    Notification::assertSentTo($this->owner, SubscriptionCanceledNotification::class);
});

it('does not cancel twice when the button is pressed twice', function () {
    Notification::fake();
    $gateway = fakeGateway();
    ($this->heldWithProvider)();

    $this->actingAs($this->owner)->delete(route('billing.cancel'));
    $this->actingAs($this->owner)->delete(route('billing.cancel'));

    expect($gateway->cancellations)->toHaveCount(1);
    Notification::assertSentToTimes($this->owner, SubscriptionCanceledNotification::class, 1);
});

it('lets a cancelled trial run to its end and skips the "you will be charged" warning', function () {
    Notification::fake();
    $gateway = fakeGateway();
    ($this->heldWithProvider)([
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->addDays(3),
        'current_period_end' => now()->addDays(3),
    ]);

    $this->actingAs($this->owner)->delete(route('billing.cancel'));

    expect($gateway->cancellations[0]['at_period_end'])->toBeTrue();

    $this->artisan('billing:trial-warnings')->assertSuccessful();

    Notification::assertNotSentTo($this->owner, TrialEndingNotification::class);
});

// Nothing is charged for a plan granted by hand, so there is no paid time to keep.
it('ends a comped plan immediately', function () {
    Notification::fake();
    $gateway = fakeGateway();
    subscriptions()->grantPlan($this->workspace, $this->price, AdminUser::factory()->create(), 'comp');

    $this->actingAs($this->owner)->delete(route('billing.cancel'))->assertRedirect();

    expect($gateway->cancellations)->toBeEmpty()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);

    Notification::assertSentTo($this->owner, SubscriptionCanceledNotification::class);
});

// A past-due period was never paid for, so there is nothing to run out.
it('ends a past-due subscription immediately', function () {
    $gateway = fakeGateway();
    ($this->heldWithProvider)(['status' => SubscriptionStatus::PastDue]);

    $this->actingAs($this->owner)->delete(route('billing.cancel'))->assertRedirect();

    expect($gateway->cancellations[0]['at_period_end'])->toBeFalse()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);
});

it('lets the owner resume before the period ends', function () {
    Notification::fake();
    $gateway = fakeGateway();
    ($this->heldWithProvider)();

    $this->actingAs($this->owner)->delete(route('billing.cancel'), ['feedback' => 'too_expensive']);
    $this->actingAs($this->owner)->post(route('billing.resume'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $subscription = Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->id)->firstOrFail();

    expect($gateway->resumptions)->toBe(['sub_dodo_1'])
        ->and($subscription->cancel_at_period_end)->toBeFalse()
        // They stayed, so the churn reason no longer describes anything.
        ->and($subscription->cancellation_feedback)->toBeNull();
});

it('keeps the cancellation when the provider cannot be reached to resume', function () {
    Notification::fake();
    $gateway = fakeGateway();
    ($this->heldWithProvider)();

    $this->actingAs($this->owner)->delete(route('billing.cancel'));

    $gateway->broken();

    $this->actingAs($this->owner)
        ->from(route('billing.index'))
        ->post(route('billing.resume'))
        ->assertRedirect(route('billing.index'))
        ->assertSessionHasErrors('errors');

    expect(Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->id)->firstOrFail()->cancel_at_period_end)->toBeTrue();
});

it('refuses resume to someone who may not manage billing', function () {
    fakeGateway();
    ($this->heldWithProvider)(['cancel_at_period_end' => true]);

    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($member)->create();

    $this->actingAs($member)->post(route('billing.resume'))->assertForbidden();
});

it('shows the billing page when access ends and whether it is cancelled', function () {
    fakeGateway();
    ($this->heldWithProvider)(['cancel_at_period_end' => true]);

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertInertia(fn ($page) => $page
            ->component('billing/index')
            ->where('subscription.cancel_at_period_end', true)
            ->whereNot('subscription.paid_through', null)
            ->whereNot('subscription.current_period_end', null));
});
