<?php

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
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
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $starter = Plan::firstWhere('code', 'starter');
    $this->addon = Addon::factory()->quantity()
        ->for(Feature::firstWhere('key', Features::SEATS))
        ->create(['key' => 'extra-seat', 'name' => 'Extra seat', 'grant_per_unit' => 1]);
    $this->price = AddonPrice::factory()->for($this->addon)->create(['amount_minor' => 900]);
    $starter->addons()->attach($this->addon);

    app(SubscriptionContract::class)->grantPlan(
        $this->workspace,
        PlanPrice::where('plan_id', $starter->id)->first(),
        AdminUser::factory()->create(),
        'seed',
    );
});

it('lists purchasable add-ons on the billing page', function () {
    $this->actingAs($this->owner)->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('addons.available', 1)
            ->where('addons.available.0.key', 'extra-seat')
            ->where('addons.available.0.amount_minor', 900)
            ->has('addons.owned', 0));
});

it('buys an add-on and reflects it immediately', function () {
    $this->actingAs($this->owner)
        ->post(route('billing.addon.store'), ['addon_price_id' => $this->price->id, 'quantity' => 2])
        ->assertRedirect();

    expect(app(EntitlementContract::class)->limitFor($this->workspace, Features::SEATS))->toBe(7)
        ->and(SubscriptionItem::withoutWorkspaceScope()->first()->quantity)->toBe(2);
});

it('shows an owned add-on with its quantity', function () {
    app(SubscriptionContract::class)->purchaseAddon($this->workspace, $this->price, 3);

    $this->actingAs($this->owner)->get(route('billing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('addons.owned', 1)
            ->where('addons.owned.0.quantity', 3)
            ->where('addons.owned.0.key', 'extra-seat'));
});

it('changes the quantity', function () {
    app(SubscriptionContract::class)->purchaseAddon($this->workspace, $this->price, 3);

    $this->actingAs($this->owner)
        ->put(route('billing.addon.update'), ['addon_id' => $this->addon->id, 'quantity' => 1])
        ->assertRedirect();

    expect(app(EntitlementContract::class)->limitFor($this->workspace, Features::SEATS))->toBe(6);
});

// Same rule as a plan downgrade: capacity in use is not taken away silently.
it('refuses to give back seats that are occupied, and says how many', function () {
    app(SubscriptionContract::class)->purchaseAddon($this->workspace, $this->price, 3);
    WorkspaceMember::factory()->for($this->workspace)->count(6)->create();

    $this->actingAs($this->owner)
        ->put(route('billing.addon.update'), ['addon_id' => $this->addon->id, 'quantity' => 0])
        ->assertSessionHasErrors('errors');

    expect(session('errors')->first('errors'))->toContain('Remove 2 more')
        ->and(app(EntitlementContract::class)->limitFor($this->workspace, Features::SEATS))->toBe(8);
});

// Section 3: admins cannot touch billing, and that includes add-ons.
it('keeps an admin away from add-ons', function () {
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();

    $this->actingAs($admin)
        ->post(route('billing.addon.store'), ['addon_price_id' => $this->price->id, 'quantity' => 1])
        ->assertForbidden();
});
