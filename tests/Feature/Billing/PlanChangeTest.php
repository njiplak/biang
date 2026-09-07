<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\DowngradeBlocked;
use App\Exceptions\Domain\PlanChangeUnavailable;
use App\Models\AddonPrice;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\AddonSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 4: "Customers can switch between the two, and between plans, at any
 * time - the price difference is prorated." Section 8 makes Dodo merchant of
 * record, so the arithmetic is theirs; the decision, and the seat check that
 * has to pass first, are ours.
 *
 * Section 7 is why it works this way at all: their own plan switcher is turned
 * OFF, because a downgrade made outside our app could not be blocked.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    // Published FIRST, then read: a price captured before this runs would hold
    // a stale null and fail for a reason that has nothing to do with the test.
    PlanPrice::all()->each(fn ($p) => $p->update(['dodo_product_id' => 'prod_'.$p->id]));

    $this->starter = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))
        ->where('billing_interval', 'month')->firstOrFail();
    $this->pro = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();
});

/** A subscription Dodo is holding, as a real paying customer would have. */
function subscribeViaProvider(PlanPrice $price): void
{
    app(SubscriptionContract::class)->grantPlan(
        test()->workspace, $price, AdminUser::factory()->create(), 'seed'
    );

    test()->workspace->fresh()->subscription->update([
        'dodo_subscription_id' => 'sub_dodo_1',
        'status' => SubscriptionStatus::Active,
    ]);
}

it('asks the provider to prorate the difference', function () {
    $gateway = fakeGateway();
    subscribeViaProvider($this->starter);

    subscriptions()->changePlan($this->workspace->fresh(), $this->pro);

    expect($gateway->planChanges)->toHaveCount(1)
        ->and($gateway->planChanges[0]['subscription'])->toBe('sub_dodo_1')
        ->and($gateway->planChanges[0]['product_id'])->toBe($this->pro->dodo_product_id);

    expect($this->workspace->fresh()->subscription->plan->code)->toBe('pro');
});

/*
 * Section 7's hard block, and the order it has to happen in: the seat check is
 * OURS and must refuse before anybody is charged. Charging first and refusing
 * second would leave a customer paying for a plan they were never moved to.
 */
it('refuses a downgrade that strands people before charging anything', function () {
    $gateway = fakeGateway();
    subscribeViaProvider($this->pro);
    WorkspaceMember::factory()->for($this->workspace)->count(7)->create();

    expect(fn () => subscriptions()->changePlan($this->workspace->fresh(), $this->starter))
        ->toThrow(DowngradeBlocked::class);

    expect($gateway->planChanges)->toBeEmpty()
        ->and($this->workspace->fresh()->subscription->plan->code)->toBe('pro');
});

/*
 * If the provider refuses, access must not move. Granting the new plan anyway
 * would hand over capacity nobody paid for, and section 8 makes their word
 * final on the money.
 */
it('leaves the plan alone when the provider refuses the change', function () {
    fakeGateway()->broken();
    subscribeViaProvider($this->starter);

    expect(fn () => subscriptions()->changePlan($this->workspace->fresh(), $this->pro))
        ->toThrow(PlanChangeUnavailable::class);

    expect($this->workspace->fresh()->subscription->plan->code)->toBe('starter');
});

/*
 * Section 14 phase 3: staff grant plans by hand and the product stays sellable
 * with no provider at all. A comped subscription has nothing to prorate, so it
 * moves locally and Dodo is never called.
 */
it('moves a hand-granted plan without involving the provider', function () {
    $gateway = fakeGateway();

    subscriptions()->grantPlan($this->workspace, $this->starter, AdminUser::factory()->create(), 'comp');

    subscriptions()->changePlan($this->workspace->fresh(), $this->pro);

    expect($gateway->planChanges)->toBeEmpty()
        ->and($this->workspace->fresh()->subscription->plan->code)->toBe('pro');
});

/*
 * Dodo replaces the whole subscription line-up on a plan change, so an add-on
 * that is not restated is silently dropped - taking the entitlement it grants
 * with it. That is a customer who upgrades and loses the extra seats they are
 * still paying for.
 */
it('carries paid add-ons across the plan change', function () {
    $this->seed(AddonSeeder::class);
    $gateway = fakeGateway();

    $seat = AddonPrice::whereHas('addon', fn ($q) => $q->where('key', 'extra-seat'))->firstOrFail();
    $seat->update(['dodo_addon_id' => 'addon_seat']);

    subscribeViaProvider($this->starter);
    subscriptions()->purchaseAddon($this->workspace->fresh(), $seat, 3);

    subscriptions()->changePlan($this->workspace->fresh(), $this->pro);

    expect($gateway->planChanges[0]['addons'])->toBe([
        ['addon_id' => 'addon_seat', 'quantity' => 3],
    ]);
});

// A plan the catalogue never published has no product to move to, and finding
// that out from a rejected API call is worse than saying so first.
it('refuses to move onto an unpublished plan', function () {
    fakeGateway();
    subscribeViaProvider($this->starter);
    $this->pro->update(['dodo_product_id' => null]);

    expect(fn () => subscriptions()->changePlan($this->workspace->fresh(), $this->pro))
        ->toThrow(PlanChangeUnavailable::class);

    expect($this->workspace->fresh()->subscription->plan->code)->toBe('starter');
});
