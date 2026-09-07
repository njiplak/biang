<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\PlanChangeUnavailable;
use App\Models\AddonPrice;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\SubscriptionItem;
use App\Models\User;
use Database\Seeders\AddonSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 4: a quantity add-on is charged "per unit, prorated when the quantity
 * changes". Until now buying one moved the entitlement and no money at all -
 * section 7's seat sale handed out a seat and never billed for it.
 *
 * The order these happen in is the point: charge, THEN grant. Granting first
 * means a declined card has already bought a seat.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->seed(AddonSeeder::class);

    PlanPrice::all()->each(fn ($p) => $p->update(['dodo_product_id' => 'prod_'.$p->id]));
    AddonPrice::all()->each(fn ($p) => $p->update(['dodo_addon_id' => 'addon_'.$p->id]));

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->planPrice = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    $this->seat = AddonPrice::whereHas('addon', fn ($q) => $q->where('key', 'extra-seat'))->firstOrFail();
});

function payingSubscription(): void
{
    subscriptions()->grantPlan(
        test()->workspace, test()->planPrice, AdminUser::factory()->create(), 'seed'
    );

    test()->workspace->fresh()->subscription->update([
        'dodo_subscription_id' => 'sub_dodo_1',
        'status' => SubscriptionStatus::Active,
    ]);
}

it('charges for a seat before granting it', function () {
    $gateway = fakeGateway();
    payingSubscription();

    subscriptions()->purchaseAddon($this->workspace->fresh(), $this->seat, 3);

    expect($gateway->planChanges)->toHaveCount(1)
        ->and($gateway->planChanges[0]['addons'])->toBe([
            ['addon_id' => $this->seat->dodo_addon_id, 'quantity' => 3],
        ]);

    expect(SubscriptionItem::withoutWorkspaceScope()->first()->quantity)->toBe(3);
});

/*
 * The failure that matters. If the charge is refused, the capacity must not be
 * granted - otherwise section 7's "sales moment" gives away a seat every time a
 * card declines.
 */
it('grants nothing when the charge is refused', function () {
    fakeGateway()->broken();
    payingSubscription();

    expect(fn () => subscriptions()->purchaseAddon($this->workspace->fresh(), $this->seat, 3))
        ->toThrow(PlanChangeUnavailable::class);

    expect(SubscriptionItem::withoutWorkspaceScope()->count())->toBe(0);
});

// Buying more of something already held is the new TOTAL at Dodo, not a second
// line - sending the delta would bill them for three and grant them five.
it('sends the new total rather than the difference', function () {
    $gateway = fakeGateway();
    payingSubscription();

    subscriptions()->purchaseAddon($this->workspace->fresh(), $this->seat, 2);
    subscriptions()->purchaseAddon($this->workspace->fresh(), $this->seat, 3);

    expect($gateway->planChanges[1]['addons'])->toBe([
        ['addon_id' => $this->seat->dodo_addon_id, 'quantity' => 5],
    ]);
});

// A reduction is a credit they owe. Not telling them would keep billing for
// capacity we have already taken away.
it('tells the provider when capacity is given back', function () {
    $gateway = fakeGateway();
    payingSubscription();

    subscriptions()->purchaseAddon($this->workspace->fresh(), $this->seat, 5);
    subscriptions()->changeAddonQuantity($this->workspace->fresh(), $this->seat->addon, 2);

    expect($gateway->planChanges[1]['addons'])->toBe([
        ['addon_id' => $this->seat->dodo_addon_id, 'quantity' => 2],
    ]);
});

it('removes the line entirely at zero', function () {
    $gateway = fakeGateway();
    payingSubscription();

    subscriptions()->purchaseAddon($this->workspace->fresh(), $this->seat, 2);
    subscriptions()->changeAddonQuantity($this->workspace->fresh(), $this->seat->addon, 0);

    expect($gateway->planChanges[1]['addons'])->toBe([])
        ->and(SubscriptionItem::withoutWorkspaceScope()->count())->toBe(0);
});

/*
 * Section 14 phase 3: staff grant plans by hand and the product stays sellable
 * with no provider at all. A comped subscription has no payment account, so an
 * add-on on it is a comp too - and must not try to charge.
 */
it('adds a comped add-on without charging anybody', function () {
    $gateway = fakeGateway();

    subscriptions()->grantPlan(
        $this->workspace, $this->planPrice, AdminUser::factory()->create(), 'comp'
    );

    subscriptions()->purchaseAddon($this->workspace->fresh(), $this->seat, 2);

    expect($gateway->planChanges)->toBeEmpty()
        ->and(SubscriptionItem::withoutWorkspaceScope()->first()->quantity)->toBe(2);
});

// An add-on nobody published cannot be attached. Dropping it from the line-up
// beats a rejected call that loses the whole change.
it('skips an add-on that was never published', function () {
    $gateway = fakeGateway();
    payingSubscription();

    $this->seat->update(['dodo_addon_id' => null]);

    subscriptions()->purchaseAddon($this->workspace->fresh(), $this->seat, 2);

    expect($gateway->planChanges[0]['addons'])->toBe([])
        ->and(SubscriptionItem::withoutWorkspaceScope()->first()->quantity)->toBe(2);
});
