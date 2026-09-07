<?php

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\AddonKind;
use App\Exceptions\Domain\AddonNotAvailable;
use App\Exceptions\Domain\DowngradeBlocked;
use App\Exceptions\Domain\NoActiveSubscription;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\Features;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->service = app(SubscriptionContract::class);
    $this->entitlements = app(EntitlementContract::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->starter = Plan::firstWhere('code', 'starter');
    $this->starterPrice = PlanPrice::where('plan_id', $this->starter->id)->first();

    // a quantity add-on granting one seat per unit, sold on the starter plan
    $this->seatAddon = Addon::factory()->quantity()
        ->for(Feature::firstWhere('key', Features::SEATS))
        ->create(['key' => 'extra-seat', 'grant_per_unit' => 1]);
    $this->seatPrice = AddonPrice::factory()->for($this->seatAddon)->create(['amount_minor' => 900]);
    $this->starter->addons()->attach($this->seatAddon);
});

function subscribe(): void
{
    test()->service->grantPlan(
        test()->workspace,
        test()->starterPrice,
        AdminUser::factory()->create(),
        'seed',
    );
}

// Section 12: a free workspace does not exist in the payment provider at all,
// so there is nothing to attach a paid add-on to. The offer for a free tier is
// an upgrade, not a seat.
it('refuses an add-on on the free tier', function () {
    expect(fn () => $this->service->purchaseAddon($this->workspace, $this->seatPrice, 1))
        ->toThrow(NoActiveSubscription::class);

    expect(SubscriptionItem::withoutWorkspaceScope()->count())->toBe(0);
});

it('raises the entitlement immediately when a seat is bought', function () {
    subscribe();
    expect($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(5);

    $this->service->purchaseAddon($this->workspace, $this->seatPrice, 3);

    expect($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(8);
});

it('records the purchase as a subscription item', function () {
    subscribe();

    $this->service->purchaseAddon($this->workspace, $this->seatPrice, 2);

    $item = SubscriptionItem::withoutWorkspaceScope()->first();

    expect($item->quantity)->toBe(2)
        ->and($item->addon_id)->toBe($this->seatAddon->id)
        ->and($item->workspace_id)->toBe($this->workspace->id);
});

it('adds to an existing item rather than creating a second one', function () {
    subscribe();

    $this->service->purchaseAddon($this->workspace, $this->seatPrice, 1);
    $this->service->purchaseAddon($this->workspace, $this->seatPrice, 2);

    expect(SubscriptionItem::withoutWorkspaceScope()->count())->toBe(1)
        ->and($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(8);
});

// Section 4: only things people actually pay extra for are add-ons, and the
// provider caps them per plan - so an add-on not sold on this plan is refused.
it('refuses an add-on that is not sold on the current plan', function () {
    subscribe();
    $other = Addon::factory()->quantity()->create(['key' => 'not-on-this-plan']);
    $otherPrice = AddonPrice::factory()->for($other)->create();

    expect(fn () => $this->service->purchaseAddon($this->workspace, $otherPrice, 1))
        ->toThrow(AddonNotAvailable::class);
});

it('refuses an archived add-on price', function () {
    subscribe();
    $archived = AddonPrice::factory()->for($this->seatAddon)->archived()->create();

    expect(fn () => $this->service->purchaseAddon($this->workspace, $archived, 1))
        ->toThrow(AddonNotAvailable::class);
});

it('lowers the quantity and the entitlement with it', function () {
    subscribe();
    $this->service->purchaseAddon($this->workspace, $this->seatPrice, 4);

    $this->service->changeAddonQuantity($this->workspace, $this->seatAddon, 1);

    expect($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(6);
});

it('removes the item entirely at zero', function () {
    subscribe();
    $this->service->purchaseAddon($this->workspace, $this->seatPrice, 2);

    $this->service->changeAddonQuantity($this->workspace, $this->seatAddon, 0);

    expect(SubscriptionItem::withoutWorkspaceScope()->count())->toBe(0)
        ->and($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(5);
});

// Same rule as a plan downgrade: we do not remove capacity that is in use, and
// we say exactly how much has to go first.
it('blocks giving back seats that are still occupied', function () {
    subscribe();
    $this->service->purchaseAddon($this->workspace, $this->seatPrice, 3);
    WorkspaceMember::factory()->for($this->workspace)->count(6)->create();

    expect(fn () => $this->service->changeAddonQuantity($this->workspace, $this->seatAddon, 0))
        ->toThrow(DowngradeBlocked::class);

    expect($this->entitlements->limitFor($this->workspace, Features::SEATS))->toBe(8);
});

// Section 7: buying the seat lifts the hard block in the same breath.
it('lifts the hard block when the bought seat covers the overage', function () {
    subscribe();
    WorkspaceMember::factory()->for($this->workspace)->count(5)->create();
    app(App\Contract\Workspace\MembershipContract::class)->syncSeats($this->workspace);
    expect($this->workspace->fresh()->canWrite())->toBeFalse();

    $this->service->purchaseAddon($this->workspace, $this->seatPrice, 2);

    expect($this->workspace->fresh()->canWrite())->toBeTrue();
});

it('treats an unlock add-on as a switch rather than a quantity', function () {
    subscribe();
    $feature = Feature::firstWhere('key', 'projects');
    $unlock = Addon::factory()->create(['key' => 'premium-reports', 'kind' => AddonKind::Unlock, 'feature_id' => $feature->id]);
    $price = AddonPrice::factory()->for($unlock)->create();
    $this->starter->addons()->attach($unlock);

    $this->service->purchaseAddon($this->workspace, $price, 1);

    expect($this->entitlements->limitFor($this->workspace, 'projects'))->toBe(1);
});
