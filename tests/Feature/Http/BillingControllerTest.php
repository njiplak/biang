<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingStatus;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
    $this->proPrice = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->first();
});

// Section 5: "Billing lives on one page inside the app: current plan, usage
// against every limit, and buttons to change plan or buy add-ons."
it('shows the billing page to someone who may manage billing', function () {
    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/index')
            ->where('workspace.state', 'free')
            ->where('subscription', null)
            // section 5: usage against EVERY limit, not only a breached one
            ->has('usage', 3)
            ->has('plans'));
});

// Section 3: admins explicitly cannot see or touch billing.
it('hides billing from an admin', function () {
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();

    $this->actingAs($admin)->get(route('billing.index'))->assertForbidden();
});

it('lets a billing manager see billing', function () {
    $billing = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($billing)->billingManager()->create();

    $this->actingAs($billing)->get(route('billing.index'))->assertOk();
});

it('starts a trial', function () {
    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => $this->proPrice->id])
        ->assertRedirect();

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Trialing)
        ->and($this->owner->fresh()->hasConsumedTrial())->toBeTrue();
});

// Section 12: one trial per person, ever - surfaced as a message, not a crash.
it('explains why a second trial is refused', function () {
    $this->owner->update(['trial_consumed_at' => now()]);

    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => $this->proPrice->id])
        ->assertSessionHasErrors('errors');

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Free);
});

it('changes plan', function () {
    app(SubscriptionContract::class)->grantPlan(
        $this->workspace,
        PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))->first(),
        AdminUser::factory()->create(),
        'seed',
    );

    $this->actingAs($this->owner)
        ->put(route('billing.plan'), ['plan_price_id' => $this->proPrice->id])
        ->assertRedirect();

    expect($this->workspace->fresh()->subscription->plan->code)->toBe('pro');
});

// Section 7: the downgrade is blocked and we say exactly how many to remove.
it('blocks a downgrade that would not fit and says so', function () {
    app(SubscriptionContract::class)->grantPlan($this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed');
    WorkspaceMember::factory()->for($this->workspace)->count(7)->create();

    $starter = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))->first();

    $response = $this->actingAs($this->owner)
        ->put(route('billing.plan'), ['plan_price_id' => $starter->id]);

    $response->assertSessionHasErrors('errors');
    expect(session('errors')->first('errors'))->toContain('Remove 3 more')
        ->and($this->workspace->fresh()->subscription->plan->code)->toBe('pro');
});

// Section 6: cancelling drops to free and deletes nothing.
it('cancels to the free tier', function () {
    app(SubscriptionContract::class)->grantPlan($this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed');

    $this->actingAs($this->owner)
        ->delete(route('billing.cancel'))
        ->assertRedirect();

    $fresh = $this->workspace->fresh();
    expect($fresh->billing_status)->toBe(BillingStatus::Free)
        ->and($fresh->canRead())->toBeTrue();
});

it('stops a member cancelling the plan', function () {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($member)->create();

    $this->actingAs($member)->delete(route('billing.cancel'))->assertForbidden();
});

it('rejects a plan price that does not exist', function () {
    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => 999999])
        ->assertSessionHasErrors('plan_price_id');
});

// Section 12: free never touches the provider, so it has no price to buy.
it('rejects an archived price', function () {
    $archived = PlanPrice::factory()->for(Plan::firstWhere('code', 'pro'))->archived()->create();

    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => $archived->id])
        ->assertSessionHasErrors('plan_price_id');
});
