<?php

use App\Contract\Billing\ReconcilerContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\SubscriptionStatus;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\Billing\SubscriptionCanceledNotification;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Section 8: cancelling works from both sides. A cancellation made on Dodo's
 * portal reaches us as an event, and the customer gets the same written
 * confirmation as one made in our app - exactly once.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    PlanPrice::all()->each(fn ($p) => $p->update(['dodo_product_id' => 'prod_'.$p->id]));

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    subscriptions()->grantPlan($this->workspace, $price, AdminUser::factory()->create(), 'seed');

    Subscription::withoutWorkspaceScope()->where('workspace_id', $this->workspace->id)->update([
        'dodo_subscription_id' => 'sub_dodo_1',
        'status' => SubscriptionStatus::Active,
        'current_period_end' => now()->addMonth(),
    ]);

    $this->reconcile = function (string $type, array $data): WebhookEvent {
        $event = WebhookEvent::create([
            'provider' => 'dodo',
            'event_id' => 'msg_'.uniqid(),
            'event_type' => $type,
            'payload' => ['data' => array_merge([
                'subscription_id' => 'sub_dodo_1',
                'status' => 'active',
                'next_billing_date' => now()->addMonth()->toIso8601String(),
                'previous_billing_date' => now()->toIso8601String(),
            ], $data)],
            'signature_verified' => true,
            'occurred_at' => now(),
            'received_at' => now(),
            'attempts' => 0,
        ]);

        app(ReconcilerContract::class)->reconcile($event);

        return $event->fresh();
    };

    $this->subscription = fn (): Subscription => Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->id)->firstOrFail();
});

it('confirms a cancellation scheduled on the provider portal', function () {
    Notification::fake();

    ($this->reconcile)('subscription.updated', ['cancel_at_next_billing_date' => true]);

    expect(($this->subscription)()->cancel_at_period_end)->toBeTrue();
    Notification::assertSentToTimes($this->owner, SubscriptionCanceledNotification::class, 1);
});

// Our own cancel already emailed; Dodo echoing it back must not email again.
it('does not confirm twice when the event echoes our own cancellation', function () {
    Notification::fake();
    fakeGateway();

    $this->actingAs($this->owner)->delete(route('billing.cancel'));
    ($this->reconcile)('subscription.updated', ['cancel_at_next_billing_date' => true]);

    Notification::assertSentToTimes($this->owner, SubscriptionCanceledNotification::class, 1);
});

it('confirms a subscription ended outright on the provider side', function () {
    Notification::fake();

    ($this->reconcile)('subscription.cancelled', [
        'status' => 'cancelled',
        'cancelled_at' => now()->toIso8601String(),
    ]);

    Notification::assertSentTo($this->owner, SubscriptionCanceledNotification::class);
});

// The grace-period emails already covered a subscription that stopped paying.
it('stays quiet when a past-due subscription ends', function () {
    Notification::fake();
    ($this->subscription)()->update(['status' => SubscriptionStatus::PastDue]);

    ($this->reconcile)('subscription.cancelled', [
        'status' => 'cancelled',
        'cancelled_at' => now()->toIso8601String(),
    ]);

    Notification::assertNotSentTo($this->owner, SubscriptionCanceledNotification::class);
});

/*
 * Closing a workspace cancels at Dodo and soft-deletes the workspace, so the
 * cancellation event arrives for a trashed workspace. It used to fail on a null
 * workspace until its retries ran out.
 */
it('processes the cancellation event for a closed workspace', function () {
    Notification::fake();
    fakeGateway();

    app(WorkspaceContract::class)->closeWorkspace($this->workspace->fresh());

    $event = ($this->reconcile)('subscription.cancelled', [
        'status' => 'cancelled',
        'cancelled_at' => now()->toIso8601String(),
    ]);

    expect($event->processed_at)->not->toBeNull()
        ->and($event->failed_at)->toBeNull();
    Notification::assertNothingSent();
});
