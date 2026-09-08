<?php

use App\Contract\Admin\CatalogContract;
use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Billing\UsageContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Exceptions\Domain\CannotArchivePlan;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 10: "Change what we sell. Create and edit plans, prices, limits and
 * add-ons without an engineer. Retire a plan without breaking the customers
 * already on it."
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->catalog = app(CatalogContract::class);
    $this->entitlements = app(EntitlementContract::class);

    $this->admin = AdminUser::factory()->create();
    $this->admin->assignRole('super-admin');

    $this->pro = Plan::where('code', 'pro')->firstOrFail();
    $this->free = Plan::where('is_free', true)->firstOrFail();
});

// ------------------------------------------------------------ prices are safe

/*
 * Prices are immutable once sold. "Editing" one is an insert plus an archive,
 * which is also what the partial unique index on (plan, interval, currency)
 * demands.
 */
it('archives the old price when a new one replaces it', function () {
    $old = $this->pro->prices()->where('billing_interval', 'month')->firstOrFail();

    $new = $this->catalog->addPrice($this->pro, [
        'billing_interval' => 'month',
        'currency' => $old->currency,
        'amount_minor' => 5900,
    ]);

    expect($old->fresh()->archived_at)->not->toBeNull()
        ->and($new->archived_at)->toBeNull()
        ->and($new->amount_minor)->toBe(5900);
});

it('does not reprice a customer already on the old price', function () {
    $workspace = app(WorkspaceContract::class)->create(User::factory()->create(), 'Acme');
    $old = $this->pro->prices()->where('billing_interval', 'month')->firstOrFail();

    app(SubscriptionContract::class)->grantPlan($workspace, $old, $this->admin, 'Deal');

    $this->catalog->addPrice($this->pro, [
        'billing_interval' => 'month',
        'currency' => $old->currency,
        'amount_minor' => 9900,
    ]);

    $subscription = $workspace->subscription()->withoutWorkspaceScope()->first();

    expect($subscription->plan_price_id)->toBe($old->id)
        ->and($subscription->planPrice->amount_minor)->toBe($old->amount_minor);
});

// ------------------------------------------------------------ retiring a plan

it('retires a plan without touching the customers on it', function () {
    $workspace = app(WorkspaceContract::class)->create(User::factory()->create(), 'Acme');
    $price = $this->pro->prices()->where('billing_interval', 'month')->firstOrFail();

    app(SubscriptionContract::class)->grantPlan($workspace, $price, $this->admin, 'Deal');
    $before = $this->entitlements->limitFor($workspace, 'seats');

    $this->catalog->archivePlan($this->pro);

    $subscription = $workspace->subscription()->withoutWorkspaceScope()->first();

    expect($this->pro->fresh()->archived_at)->not->toBeNull()
        // The customer keeps the plan, the price and the limits they were sold.
        ->and($subscription->plan_id)->toBe($this->pro->id)
        ->and($subscription->status->isLive())->toBeTrue()
        ->and($this->entitlements->limitFor($workspace, 'seats'))->toBe($before);
});

it('takes a retired plan off sale', function () {
    $this->catalog->archivePlan($this->pro);

    expect($this->pro->fresh()->is_public)->toBeFalse()
        ->and($this->pro->prices()->whereNull('archived_at')->count())->toBe(0)
        ->and(Plan::query()->public()->pluck('code'))->not->toContain('pro');
});

/*
 * Section 6: cancelling drops a workspace onto the free tier. Retiring it would
 * leave that path with nowhere to land.
 */
it('refuses to retire the free plan', function () {
    expect(fn () => $this->catalog->archivePlan($this->free))
        ->toThrow(CannotArchivePlan::class);

    expect($this->free->fresh()->archived_at)->toBeNull();
});

it('does not put stale prices back on sale when un-retiring', function () {
    $this->catalog->archivePlan($this->pro);
    $this->catalog->restorePlan($this->pro);

    expect($this->pro->fresh()->archived_at)->toBeNull()
        ->and($this->pro->prices()->whereNull('archived_at')->count())->toBe(0);
});

// ------------------------------------------------------- limits move customers

/*
 * The point of the whole screen. workspace_entitlements is a materialised
 * snapshot that section 7's hard block reads on every write, so a limit change
 * that does not rebuild leaves customers enforcing yesterday's numbers.
 */
it('rebuilds every workspace on a plan when its limits change', function () {
    $workspace = app(WorkspaceContract::class)->create(User::factory()->create(), 'Acme');
    $price = $this->pro->prices()->where('billing_interval', 'month')->firstOrFail();
    app(SubscriptionContract::class)->grantPlan($workspace, $price, $this->admin, 'Deal');

    expect($this->entitlements->limitFor($workspace, 'seats'))->toBe(25);

    $seats = $this->pro->features()->where('key', 'seats')->firstOrFail();
    $this->catalog->syncFeatures($this->pro, [$seats->id => 60]);

    expect($this->entitlements->limitFor($workspace, 'seats'))->toBe(60);
});

/*
 * The awkward one: a workspace with no subscription has none to join against -
 * EntitlementService falls back to the FLOOR plan - so it cannot be found by
 * joining subscriptions and has to be swept separately.
 *
 * The seeded floor grants unlimited, so this gives it a number first. That is
 * the only way to see the sweep happen at all.
 */
it('rebuilds workspaces resting on the floor when the floor plan changes', function () {
    $seats = $this->free->features()->where('key', 'seats')->firstOrFail();
    $this->catalog->syncFeatures($this->free, [$seats->id => 2]);

    $workspace = app(WorkspaceContract::class)->create(User::factory()->create(), 'Acme');

    expect($this->entitlements->limitFor($workspace, 'seats'))->toBe(2);

    $this->catalog->syncFeatures($this->free, [$seats->id => 5]);

    expect($this->entitlements->limitFor($workspace, 'seats'))->toBe(5);
});

it('leaves a workspace on another plan untouched', function () {
    $onPro = app(WorkspaceContract::class)->create(User::factory()->create(), 'Pro Co');
    $price = $this->pro->prices()->where('billing_interval', 'month')->firstOrFail();
    app(SubscriptionContract::class)->grantPlan($onPro, $price, $this->admin, 'Deal');

    $starter = Plan::where('code', 'starter')->firstOrFail();
    $seats = $starter->features()->where('key', 'seats')->firstOrFail();
    $this->catalog->syncFeatures($starter, [$seats->id => 99]);

    expect($this->entitlements->limitFor($onPro, 'seats'))->toBe(25);
});

it('records an unlimited limit as null', function () {
    $workspace = app(WorkspaceContract::class)->create(User::factory()->create(), 'Acme');
    $seats = $this->free->features()->where('key', 'seats')->firstOrFail();

    $this->catalog->syncFeatures($this->free, [$seats->id => null]);

    expect($this->entitlements->limitFor($workspace, 'seats'))->toBeNull()
        ->and($this->entitlements->allows($workspace, 'seats', 5000))->toBeTrue();
});

// A raised limit has to release a blocked customer in the same breath.
it('lifts the hard block when a plan limit is raised', function () {
    // The floor grants unlimited, so it has to be given a ceiling before a
    // workspace resting on it can be over one.
    $seats = $this->free->features()->where('key', 'seats')->firstOrFail();
    $this->catalog->syncFeatures($this->free, [$seats->id => 2]);

    $workspace = app(WorkspaceContract::class)->create(User::factory()->create(), 'Acme');

    app(UsageContract::class)->setGauge($workspace, 'seats', 4);
    app(UsageContract::class)->evaluate($workspace);
    expect($workspace->fresh()->isOverLimit())->toBeTrue();

    $this->catalog->syncFeatures($this->free, [$seats->id => 10]);
    app(UsageContract::class)->evaluate($workspace->fresh());

    expect($workspace->fresh()->isOverLimit())->toBeFalse();
});

// ------------------------------------------------------------------- the HTTP

it('shows the catalog to staff who may manage plans', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.catalog.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/catalog/index')
            ->has('plans', 3)
            ->has('features')
            ->has('addons'));
});

it('creates a plan', function () {
    $this->actingAs($this->admin, 'admin')
        ->post(route('admin.catalog.plan.store'), [
            'code' => 'scale',
            'name' => 'Scale',
            'is_public' => true,
            'sort_order' => 40,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Plan::where('code', 'scale')->exists())->toBeTrue();
});

it('refuses a duplicate plan code', function () {
    $this->actingAs($this->admin, 'admin')
        ->post(route('admin.catalog.plan.store'), ['code' => 'pro', 'name' => 'Pro again'])
        ->assertSessionHasErrors('code');
});

// The code is how seeders, tests and integrations address a plan.
it('never changes a plan code on update', function () {
    $this->actingAs($this->admin, 'admin')
        ->put(route('admin.catalog.plan.update', $this->pro), [
            'code' => 'renamed',
            'name' => 'Pro Plus',
        ])
        ->assertRedirect();

    expect($this->pro->fresh()->code)->toBe('pro')
        ->and($this->pro->fresh()->name)->toBe('Pro Plus');
});

it('refuses to archive a price belonging to another plan', function () {
    // A currency the seeder does not use, so this does not collide with the
    // partial unique index on (plan, interval, currency).
    $other = PlanPrice::factory()
        ->for(Plan::where('code', 'starter')->firstOrFail())
        ->create(['currency' => 'EUR']);

    $this->actingAs($this->admin, 'admin')
        ->delete(route('admin.catalog.plan.price.archive', [$this->pro, $other]))
        ->assertNotFound();

    expect($other->fresh()->archived_at)->toBeNull();
});

// Section 10 gives this to sales, not support.
it('refuses staff without the plan permission', function () {
    $support = AdminUser::factory()->create();
    $support->assignRole('support');

    $this->actingAs($support, 'admin')
        ->get(route('admin.catalog.index'))
        ->assertForbidden();
});

it('keeps customers out of the catalog', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.catalog.index'))
        ->assertRedirect(route('admin.login'));
});
