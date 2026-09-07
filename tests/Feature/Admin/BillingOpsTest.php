<?php

use App\Contract\Admin\BillingOpsContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingSource;
use App\Enums\BillingStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AdminUser;
use App\Models\DunningState;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 8's operational half. Everything the payment integration records when
 * something goes wrong was, until this screen, readable only by querying the
 * database - which means finding out from the customer.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->ops = app(BillingOpsContract::class);

    $this->finance = AdminUser::factory()->create();
    $this->finance->assignRole('finance');

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    $this->subscription = Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->price->plan_id,
        'plan_price_id' => $this->price->id,
        'status' => SubscriptionStatus::Active,
        'dodo_subscription_id' => 'sub_1',
    ]);
});

// ---------------------------------------------------------- failed webhooks

it('surfaces a webhook that could not be applied', function () {
    WebhookEvent::create([
        'provider' => 'dodo',
        'event_id' => 'msg_broken',
        'event_type' => 'subscription.active',
        'payload' => ['type' => 'subscription.active'],
        'signature_verified' => true,
        'occurred_at' => now(),
        'received_at' => now(),
        'failed_at' => now(),
        'error' => 'No matching subscription.',
        'attempts' => 1,
    ]);

    $overview = $this->ops->overview();

    expect($overview['failed_webhooks'])->toHaveCount(1)
        ->and($overview['failed_webhooks'][0]['event_id'])->toBe('msg_broken')
        ->and($overview['failed_webhooks'][0]['error'])->toBe('No matching subscription.')
        ->and($overview['failed_webhooks'][0]['can_retry'])->toBeTrue();
});

it('leaves a processed webhook out of the failures', function () {
    WebhookEvent::create([
        'provider' => 'dodo',
        'event_id' => 'msg_ok',
        'event_type' => 'subscription.active',
        'payload' => [],
        'signature_verified' => true,
        'occurred_at' => now(),
        'received_at' => now(),
        'processed_at' => now(),
    ]);

    expect($this->ops->overview()['failed_webhooks'])->toBeEmpty();
});

// A retry must never be able to apply something intake refused.
it('does not offer a retry on an unverified event', function () {
    WebhookEvent::create([
        'provider' => 'dodo',
        'event_id' => 'msg_unsigned',
        'event_type' => 'subscription.active',
        'payload' => [],
        'signature_verified' => false,
        'occurred_at' => now(),
        'received_at' => now(),
        'failed_at' => now(),
        'error' => 'Signature was not verified.',
    ]);

    expect($this->ops->overview()['failed_webhooks'][0]['can_retry'])->toBeFalse();
});

/*
 * The reason a retry is useful: an event that arrived before the subscription
 * it refers to now has something to match against.
 */
it('applies a retried event that can now be matched', function () {
    $event = WebhookEvent::create([
        'provider' => 'dodo',
        'event_id' => 'msg_retry',
        'event_type' => 'subscription.cancelled',
        'payload' => [
            'type' => 'subscription.cancelled',
            'data' => ['subscription_id' => 'sub_1'],
        ],
        'signature_verified' => true,
        'occurred_at' => now(),
        'received_at' => now(),
        'failed_at' => now(),
        'error' => 'No matching subscription.',
        'attempts' => 1,
    ]);

    $this->actingAs($this->finance, 'admin')
        ->post(route('admin.billing-ops.retry', $event))
        ->assertRedirect();

    expect($event->fresh()->processed_at)->not->toBeNull()
        ->and($event->fresh()->failed_at)->toBeNull()
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Canceled)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Free);
});

// The reconciler re-checks the flag itself, so this is the second lock.
it('refuses to apply a retried event that was never verified', function () {
    $event = WebhookEvent::create([
        'provider' => 'dodo',
        'event_id' => 'msg_forged',
        'event_type' => 'subscription.cancelled',
        'payload' => [
            'type' => 'subscription.cancelled',
            'data' => ['subscription_id' => 'sub_1'],
        ],
        'signature_verified' => false,
        'occurred_at' => now(),
        'received_at' => now(),
        'failed_at' => now(),
    ]);

    $this->ops->retry($event);

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($event->fresh()->failed_at)->not->toBeNull();
});

// ----------------------------------------------------------------- dunning

it('lists workspaces in dunning by how soon their grace runs out', function () {
    $second = app(WorkspaceContract::class)->create(User::factory()->create(), 'Later Co');
    $secondSub = Subscription::factory()->for($second)->create([
        'plan_id' => $this->price->plan_id,
        'plan_price_id' => $this->price->id,
        'status' => SubscriptionStatus::PastDue,
    ]);

    DunningState::withoutWorkspaceScope()->create([
        'workspace_id' => $second->id,
        'subscription_id' => $secondSub->id,
        'started_at' => now(),
        'grace_ends_at' => now()->addDays(10),
    ]);
    DunningState::withoutWorkspaceScope()->create([
        'workspace_id' => $this->workspace->id,
        'subscription_id' => $this->subscription->id,
        'started_at' => now(),
        'grace_ends_at' => now()->addDays(2),
    ]);

    $dunning = $this->ops->overview()['dunning'];

    // Most urgent first: that is the only ordering staff can act on.
    expect($dunning)->toHaveCount(2)
        ->and($dunning[0]['workspace_name'])->toBe('Acme Inc')
        ->and($dunning[0]['grace_expired'])->toBeFalse();
});

it('flags a grace window that has already run out', function () {
    DunningState::withoutWorkspaceScope()->create([
        'workspace_id' => $this->workspace->id,
        'subscription_id' => $this->subscription->id,
        'started_at' => now()->subMonth(),
        'grace_ends_at' => now()->subDay(),
    ]);

    expect($this->ops->overview()['dunning'][0]['grace_expired'])->toBeTrue();
});

it('drops a dunning episode once it is resolved', function () {
    DunningState::withoutWorkspaceScope()->create([
        'workspace_id' => $this->workspace->id,
        'subscription_id' => $this->subscription->id,
        'started_at' => now(),
        'grace_ends_at' => now()->addDays(5),
        'resolution' => \App\Enums\DunningResolution::Recovered,
        'resolved_at' => now(),
    ]);

    expect($this->ops->overview()['dunning'])->toBeEmpty();
});

// --------------------------------------------------------------- integrity

/*
 * Subscription::isMissingProviderRecord() calls itself "a data-integrity alarm,
 * not a business state". This is what monitors it.
 */
it('raises an alarm on a provider subscription with no provider id', function () {
    $this->subscription->update([
        'billing_source' => BillingSource::Dodo,
        'dodo_subscription_id' => null,
    ]);

    $alarms = $this->ops->overview()['integrity'];

    expect($alarms)->toHaveCount(1)
        ->and($alarms[0]['workspace_name'])->toBe('Acme Inc');
});

// A comp is SUPPOSED to have no provider record - that is the whole reason
// billing_source exists, and mistaking it for a fault would cry wolf forever.
it('does not mistake a comped subscription for a broken sync', function () {
    $this->subscription->update([
        'billing_source' => BillingSource::Manual,
        'dodo_subscription_id' => null,
        'grant_reason' => 'Launch partner',
    ]);

    expect($this->ops->overview()['integrity'])->toBeEmpty();
});

it('ignores a subscription that already ended', function () {
    $this->subscription->update([
        'billing_source' => BillingSource::Dodo,
        'dodo_subscription_id' => null,
        'status' => SubscriptionStatus::Canceled,
    ]);

    expect($this->ops->overview()['integrity'])->toBeEmpty();
});

// ------------------------------------------------------------------ access

it('shows the screen to finance', function () {
    $this->actingAs($this->finance, 'admin')
        ->get(route('admin.billing-ops.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/billing-ops/index')
            ->has('failed_webhooks')
            ->has('dunning')
            ->has('integrity')
            ->has('recent_webhooks'));
});

// Support answers tickets; the money is finance's.
it('refuses staff without revenue.view', function () {
    $support = AdminUser::factory()->create();
    $support->assignRole('support');

    $this->actingAs($support, 'admin')
        ->get(route('admin.billing-ops.index'))
        ->assertForbidden();
});

it('keeps customers out entirely', function () {
    $this->actingAs($this->owner)
        ->get(route('admin.billing-ops.index'))
        ->assertRedirect(route('admin.login'));
});
