<?php

use App\Contract\Billing\EntitlementContract;
use App\Enums\EntitlementSource;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceEntitlementOverride;
use App\Support\CurrentWorkspace;

beforeEach(function () {
    $this->service = app(EntitlementContract::class);
    $this->seats = Feature::factory()->create(['key' => 'seats']);
    $this->floorPlan = Plan::factory()->floor()->create();
    $this->floorPlan->features()->attach($this->seats, ['value' => 2]);
});

it('resolves the free plan for a workspace with no subscription', function () {
    $ws = Workspace::factory()->create();

    $this->service->rebuild($ws);

    $entitlement = WorkspaceEntitlement::withoutWorkspaceScope()
        ->where('workspace_id', $ws->id)->where('feature_key', 'seats')->first();

    expect($entitlement->value)->toBe(2)
        ->and($entitlement->source)->toBe(EntitlementSource::Plan);
});

it('resolves the subscribed plan instead of the free one', function () {
    $ws = Workspace::factory()->create();
    $pro = Plan::factory()->create();
    $pro->features()->attach($this->seats, ['value' => 25]);
    Subscription::factory()->for($ws)->for($pro)->create();

    $this->service->rebuild($ws);

    expect($this->service->limitFor($ws, 'seats'))->toBe(25);
});

// Section 4: a quantity add-on grants more of a feature, and must resolve
// through the same path as the plan's own allowance.
it('adds quantity add-on grants on top of the plan allowance', function () {
    $ws = Workspace::factory()->create();
    $pro = Plan::factory()->create();
    $pro->features()->attach($this->seats, ['value' => 5]);
    $sub = Subscription::factory()->for($ws)->for($pro)->create();

    $addon = Addon::factory()->quantity()->for($this->seats)->create(['grant_per_unit' => 1]);
    SubscriptionItem::create([
        'workspace_id' => $ws->id,
        'subscription_id' => $sub->id,
        'addon_id' => $addon->id,
        'addon_price_id' => AddonPrice::factory()->for($addon)->create()->id,
        'quantity' => 3,
    ]);

    $this->service->rebuild($ws);

    $entitlement = WorkspaceEntitlement::withoutWorkspaceScope()
        ->where('workspace_id', $ws->id)->where('feature_key', 'seats')->first();

    expect($entitlement->value)->toBe(8)
        ->and($entitlement->source)->toBe(EntitlementSource::Addon);
});

// Section 10: "Override a limit for one specific customer."
it('lets a staff override replace the resolved value entirely', function () {
    $ws = Workspace::factory()->create();
    WorkspaceEntitlementOverride::factory()->for($ws)->for($this->seats)->create(['value' => 500]);

    $this->service->rebuild($ws);

    $entitlement = WorkspaceEntitlement::withoutWorkspaceScope()
        ->where('workspace_id', $ws->id)->where('feature_key', 'seats')->first();

    expect($entitlement->value)->toBe(500)
        ->and($entitlement->source)->toBe(EntitlementSource::Override);
});

it('ignores a revoked override', function () {
    $ws = Workspace::factory()->create();
    WorkspaceEntitlementOverride::factory()->for($ws)->for($this->seats)->revoked()->create(['value' => 500]);

    $this->service->rebuild($ws);

    expect($this->service->limitFor($ws, 'seats'))->toBe(2);
});

it('ignores an expired override', function () {
    $ws = Workspace::factory()->create();
    WorkspaceEntitlementOverride::factory()->for($ws)->for($this->seats)
        ->create(['value' => 500, 'expires_at' => now()->subDay()]);

    $this->service->rebuild($ws);

    expect($this->service->limitFor($ws, 'seats'))->toBe(2);
});

it('treats null as unlimited and lets it win over any number', function () {
    $ws = Workspace::factory()->create();
    $pro = Plan::factory()->create();
    $pro->features()->attach($this->seats, ['value' => null]);
    $sub = Subscription::factory()->for($ws)->for($pro)->create();

    $addon = Addon::factory()->quantity()->for($this->seats)->create(['grant_per_unit' => 1]);
    SubscriptionItem::create([
        'workspace_id' => $ws->id,
        'subscription_id' => $sub->id,
        'addon_id' => $addon->id,
        'addon_price_id' => AddonPrice::factory()->for($addon)->create()->id,
        'quantity' => 3,
    ]);

    $this->service->rebuild($ws);

    expect($this->service->limitFor($ws, 'seats'))->toBeNull()
        ->and($this->service->allows($ws, 'seats', 999999))->toBeTrue();
});

it('replaces the previous snapshot rather than accumulating rows', function () {
    $ws = Workspace::factory()->create();

    $this->service->rebuild($ws);
    $this->service->rebuild($ws);
    $this->service->rebuild($ws);

    expect(WorkspaceEntitlement::withoutWorkspaceScope()->where('workspace_id', $ws->id)->count())->toBe(1);
});

it('answers allows() against the resolved limit', function () {
    $ws = Workspace::factory()->create();
    $this->service->rebuild($ws);

    expect($this->service->allows($ws, 'seats', 2))->toBeTrue()
        ->and($this->service->allows($ws, 'seats', 3))->toBeFalse();
});

// A feature nobody has granted is not implicitly unlimited - that would turn a
// typo in a feature key into free capacity.
it('denies a feature the workspace has no entitlement for', function () {
    $ws = Workspace::factory()->create();
    $this->service->rebuild($ws);

    expect($this->service->allows($ws, 'unknown_feature', 1))->toBeFalse();
});

// Services take the workspace explicitly, so the admin console and queued jobs
// can operate on a workspace that is not the ambient one.
it('rebuilds a workspace that is not the current one', function () {
    $current = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    app(CurrentWorkspace::class)->set($current);

    $this->service->rebuild($other);

    expect($this->service->limitFor($other, 'seats'))->toBe(2);
});
