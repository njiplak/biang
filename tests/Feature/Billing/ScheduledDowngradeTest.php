<?php

use App\Contract\Billing\ReconcilerContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\PlanChangeScheduled;
use App\Models\AddonPrice;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\Billing\PlanChangedNotification;
use Database\Seeders\AddonSeeder;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * A downgrade waits for the renewal, so the customer keeps what they already
 * paid for. An upgrade still applies at once - they are paying for more.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    PlanPrice::all()->each(fn ($p) => $p->update(['dodo_product_id' => 'prod_'.$p->id]));

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $price = fn (string $plan, string $interval) => PlanPrice::whereHas('plan', fn ($q) => $q->where('code', $plan))
        ->where('billing_interval', $interval)->firstOrFail();

    $this->starter = $price('starter', 'month');
    $this->pro = $price('pro', 'month');
    $this->proYearly = $price('pro', 'year');

    // A paying Dodo subscription, a month into the future.
    $this->onPlan = function (PlanPrice $on): Subscription {
        subscriptions()->grantPlan($this->workspace, $on, AdminUser::factory()->create(), 'seed');

        $subscription = Subscription::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)->live()->firstOrFail();

        $subscription->update([
            'dodo_subscription_id' => 'sub_dodo_1',
            'status' => SubscriptionStatus::Active,
            'current_period_end' => now()->addMonth(),
        ]);

        return $subscription->fresh();
    };

    $this->live = fn (): Subscription => Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->id)->live()->firstOrFail();

    $this->event = fn (string $type, array $data) => WebhookEvent::create([
        'provider' => 'dodo',
        'event_id' => 'msg_'.uniqid(),
        'event_type' => $type,
        'payload' => ['data' => array_merge([
            'subscription_id' => 'sub_dodo_1',
            'status' => 'active',
            'next_billing_date' => now()->addMonths(2)->toIso8601String(),
            'previous_billing_date' => now()->toIso8601String(),
        ], $data)],
        'signature_verified' => true,
        'occurred_at' => now(),
        'received_at' => now(),
        'attempts' => 0,
    ]);
});

it('schedules a downgrade for the renewal and keeps the current plan until then', function () {
    Notification::fake();
    $gateway = fakeGateway();
    $subscription = ($this->onPlan)($this->pro);

    subscriptions()->changePlan($this->workspace->fresh(), $this->starter);

    $fresh = ($this->live)();

    expect($gateway->planChanges[0]['at_next_billing_date'])->toBeTrue()
        ->and($fresh->plan->code)->toBe('pro')
        ->and($fresh->scheduled_plan_price_id)->toBe($this->starter->id)
        ->and($fresh->scheduled_change_at->toDateString())->toBe($subscription->current_period_end->toDateString());

    Notification::assertSentTo($this->owner, PlanChangedNotification::class);
});

it('treats annual to monthly on the same plan as a downgrade', function () {
    $gateway = fakeGateway();
    ($this->onPlan)($this->proYearly);

    subscriptions()->changePlan($this->workspace->fresh(), $this->pro);

    expect($gateway->planChanges[0]['at_next_billing_date'])->toBeTrue()
        ->and(($this->live)()->plan_price_id)->toBe($this->proYearly->id);
});

it('still applies an upgrade immediately', function () {
    $gateway = fakeGateway();
    ($this->onPlan)($this->starter);

    subscriptions()->changePlan($this->workspace->fresh(), $this->pro);

    expect($gateway->planChanges[0]['at_next_billing_date'])->toBeFalse()
        ->and(($this->live)()->plan->code)->toBe('pro');
});

// Nothing is charged for a plan granted by hand, so there is no paid time to keep.
it('moves a comped plan down immediately', function () {
    fakeGateway();
    subscriptions()->grantPlan($this->workspace, $this->pro, AdminUser::factory()->create(), 'comp');

    subscriptions()->changePlan($this->workspace->fresh(), $this->starter);

    expect(($this->live)()->plan->code)->toBe('starter');
});

it('lets the owner keep the current plan instead', function () {
    $gateway = fakeGateway();
    ($this->onPlan)($this->pro);
    subscriptions()->changePlan($this->workspace->fresh(), $this->starter);

    $this->actingAs($this->owner)
        ->delete(route('billing.plan.keep'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($gateway->scheduledChangeCancellations)->toBe(['sub_dodo_1'])
        ->and(($this->live)()->scheduled_plan_price_id)->toBeNull();
});

it('replaces a scheduled downgrade when they upgrade instead', function () {
    $gateway = fakeGateway();
    ($this->onPlan)($this->pro);
    subscriptions()->changePlan($this->workspace->fresh(), $this->starter);

    subscriptions()->changePlan($this->workspace->fresh(), $this->proYearly);

    expect($gateway->planChanges[1]['replace_scheduled'])->toBeTrue()
        ->and($gateway->planChanges[1]['at_next_billing_date'])->toBeFalse()
        ->and(($this->live)()->plan_price_id)->toBe($this->proYearly->id)
        ->and(($this->live)()->scheduled_plan_price_id)->toBeNull();
});

// Dodo refuses changes while a schedule is pending; say so before asking them.
it('refuses add-on changes while a downgrade is scheduled', function () {
    $this->seed(AddonSeeder::class);
    fakeGateway();
    ($this->onPlan)($this->pro);
    subscriptions()->changePlan($this->workspace->fresh(), $this->starter);

    $seat = AddonPrice::whereHas('addon', fn ($q) => $q->where('key', 'extra-seat'))->firstOrFail();

    expect(fn () => subscriptions()->purchaseAddon($this->workspace->fresh(), $seat, 1))
        ->toThrow(PlanChangeScheduled::class);
});

it('quotes the date, not a charge, for a downgrade', function () {
    $gateway = fakeGateway();
    ($this->onPlan)($this->pro);

    $this->actingAs($this->owner)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->starter->id]))
        ->assertOk()
        ->assertJsonPath('preview', null)
        ->assertJsonStructure(['effective_at']);

    expect($gateway->previews)->toBeEmpty();
});

it('moves the plan when the renewal lands on the scheduled product', function () {
    fakeGateway();
    ($this->onPlan)($this->pro);
    subscriptions()->changePlan($this->workspace->fresh(), $this->starter);

    app(ReconcilerContract::class)->reconcile(($this->event)('subscription.renewed', [
        'product_id' => $this->starter->dodo_product_id,
    ]));

    $fresh = ($this->live)();

    expect($fresh->plan->code)->toBe('starter')
        ->and($fresh->scheduled_plan_price_id)->toBeNull();
});

it('forgets a schedule that was dropped on the provider side', function () {
    fakeGateway();
    ($this->onPlan)($this->pro);
    subscriptions()->changePlan($this->workspace->fresh(), $this->starter);

    app(ReconcilerContract::class)->reconcile(($this->event)('subscription.updated', [
        'product_id' => $this->pro->dodo_product_id,
        'scheduled_change' => null,
    ]));

    $fresh = ($this->live)();

    expect($fresh->plan->code)->toBe('pro')
        ->and($fresh->scheduled_plan_price_id)->toBeNull();
});

it('shows staff the scheduled change and a scheduled cancellation', function () {
    fakeGateway();
    $this->seed(AdminRoleSeeder::class);
    $admin = AdminUser::factory()->create();
    $admin->assignRole('super-admin');

    ($this->onPlan)($this->pro);
    subscriptions()->changePlan($this->workspace->fresh(), $this->starter);
    ($this->live)()->update(['cancel_at_period_end' => true]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.customer.show', $this->workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('subscription.cancel_at_period_end', true)
            ->whereNot('subscription.ends_at', null)
            ->where('subscription.scheduled_plan', 'Starter'));
});
