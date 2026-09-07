<?php

use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanPrice;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

/*
 * Section 10: "Change what we sell ... without an engineer." Section 8 makes
 * Dodo the seller, so a price is only real once it exists in BOTH places.
 *
 * This was the gap that made every checkout impossible: `dodo_product_id` was
 * read by the gateway and written by nothing, so every price failed with "not
 * published to the payment provider yet" - permanently, for every customer.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->seed(AdminRoleSeeder::class);

    config(['dodo.api_key' => 'key_test']);

    $this->staff = AdminUser::factory()->create();
    $this->staff->assignRole('super-admin');

    $this->plan = Plan::firstWhere('code', 'pro');
});

it('publishes a new plan price so it can actually be bought', function () {
    $gateway = fakeGateway();

    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.catalog.plan.price.store', $this->plan), [
            'billing_interval' => 'month',
            'currency' => 'USD',
            'amount_minor' => 5900,
        ])
        ->assertRedirect();

    $price = PlanPrice::where('plan_id', $this->plan->id)->whereNull('archived_at')
        ->where('billing_interval', 'month')->firstOrFail();

    expect($price->dodo_product_id)->toBe('prod_1')
        ->and($gateway->published)->toHaveCount(1)
        ->and($gateway->published[0]['name'])->toBe('Pro (Monthly)')
        ->and($gateway->published[0]['amountMinor'])->toBe(5900)
        ->and($gateway->published[0]['interval'])->toBe('month');
});

// An annual plan is ONE Year billed every Year. Getting this wrong bills a
// yearly customer monthly, which is a refund and a chargeback, not a bug report.
it('publishes an annual price as a yearly product', function () {
    $gateway = fakeGateway();

    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.catalog.plan.price.store', $this->plan), [
            'billing_interval' => 'year',
            'currency' => 'USD',
            'amount_minor' => 59000,
        ]);

    expect($gateway->published[0]['amountMinor'])->toBe(59000)
        ->and($gateway->published[0]['interval'])->toBe('year')
        ->and($gateway->published[0]['name'])->toBe('Pro (Yearly)');
});

/*
 * Section 14 phase 3: the product stays sellable by hand with no provider wired
 * up. So a provider outage must never lose the staff member's work - the price
 * is saved, it simply is not on sale, and they are told which.
 */
it('keeps the price when the provider refuses it', function () {
    fakeGateway()->broken();

    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.catalog.plan.price.store', $this->plan), [
            'billing_interval' => 'month',
            'currency' => 'USD',
            'amount_minor' => 5900,
        ])
        ->assertSessionHasErrors('errors');

    $price = PlanPrice::where('plan_id', $this->plan->id)->whereNull('archived_at')
        ->where('billing_interval', 'month')->firstOrFail();

    expect($price->exists)->toBeTrue()
        ->and($price->dodo_product_id)->toBeNull();
});

// The retry, so an unsellable price is not a deploy to fix.
it('publishes a price that failed the first time', function () {
    $price = PlanPrice::where('plan_id', $this->plan->id)->whereNull('archived_at')->first();
    $price->update(['dodo_product_id' => null]);

    fakeGateway();

    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.catalog.plan.price.publish', ['plan' => $this->plan, 'price' => $price]))
        ->assertRedirect();

    expect($price->fresh()->dodo_product_id)->toBe('prod_1');
});

/*
 * Publishing twice would mint a second product and leave the first collecting
 * subscriptions we no longer point at - an invisible split of one plan's
 * revenue across two products.
 */
it('never publishes the same price twice', function () {
    $gateway = fakeGateway();

    $price = PlanPrice::where('plan_id', $this->plan->id)->whereNull('archived_at')->first();
    $price->update(['dodo_product_id' => 'prod_existing']);

    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.catalog.plan.price.publish', ['plan' => $this->plan, 'price' => $price]));

    expect($gateway->published)->toBeEmpty()
        ->and($price->fresh()->dodo_product_id)->toBe('prod_existing');
});

// Section 10: "Retire a plan without breaking the customers already on it."
it('retires the provider product when a price is archived', function () {
    $gateway = fakeGateway();

    $price = PlanPrice::where('plan_id', $this->plan->id)->whereNull('archived_at')->first();
    $price->update(['dodo_product_id' => 'prod_live']);

    $this->actingAs($this->staff, 'admin')
        ->delete(route('admin.catalog.plan.price.archive', ['plan' => $this->plan, 'price' => $price]));

    expect($gateway->archived)->toBe(['prod_live'])
        ->and($price->fresh()->archived_at)->not->toBeNull();
});

it('retires every live price when the whole plan is retired', function () {
    $gateway = fakeGateway();

    PlanPrice::where('plan_id', $this->plan->id)->whereNull('archived_at')
        ->get()->each(fn ($p, $i) => $p->update(['dodo_product_id' => 'prod_'.$p->id]));

    $expected = PlanPrice::where('plan_id', $this->plan->id)->whereNull('archived_at')
        ->pluck('dodo_product_id')->all();

    $this->actingAs($this->staff, 'admin')
        ->delete(route('admin.catalog.plan.archive', $this->plan));

    expect($gateway->archived)->toEqualCanonicalizing($expected)
        ->and($expected)->not->toBeEmpty();
});

/*
 * Retiring is OUR decision and has already happened in our records. A provider
 * we cannot reach must not be able to veto it, or a plan we have stopped selling
 * stays on the pricing page because their API was down.
 */
it('still retires a plan when the provider cannot be reached', function () {
    $price = PlanPrice::where('plan_id', $this->plan->id)->whereNull('archived_at')->first();
    $price->update(['dodo_product_id' => 'prod_live']);

    fakeGateway()->broken();

    $this->actingAs($this->staff, 'admin')
        ->delete(route('admin.catalog.plan.price.archive', ['plan' => $this->plan, 'price' => $price]))
        ->assertRedirect();

    expect($price->fresh()->archived_at)->not->toBeNull();
});

// Section 10: staff must be able to SEE which prices are actually sellable.
it('shows staff which prices are not on sale', function () {
    fakeGateway();

    PlanPrice::query()->update(['dodo_product_id' => null]);

    $this->actingAs($this->staff, 'admin')
        ->get(route('admin.catalog.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.1.prices.0.is_published', false));

    // unique(dodo_product_id): each price needs its own, as in real life
    PlanPrice::all()->each(fn ($p) => $p->update(['dodo_product_id' => 'prod_'.$p->id]));

    $this->actingAs($this->staff, 'admin')
        ->get(route('admin.catalog.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.1.prices.0.is_published', true));
});

// ------------------------------------------------------------- the backfill

it('publishes every unpublished price in one go', function () {
    $gateway = fakeGateway();

    PlanPrice::query()->update(['dodo_product_id' => null]);
    $expected = PlanPrice::whereNull('archived_at')->count();

    $this->artisan('billing:publish-catalog')->assertSuccessful();

    expect($gateway->published)->toHaveCount($expected)
        ->and(PlanPrice::whereNull('archived_at')->whereNull('dodo_product_id')->count())->toBe(0);
});

it('leaves an archived price alone', function () {
    $gateway = fakeGateway();

    PlanPrice::query()->update(['dodo_product_id' => null, 'archived_at' => now()]);

    $this->artisan('billing:publish-catalog')->assertSuccessful();

    expect($gateway->published)->toBeEmpty();
});

// One rejected price must not leave the rest of the catalogue unsellable.
it('reports a failure without abandoning the rest', function () {
    fakeGateway()->broken();
    PlanPrice::query()->update(['dodo_product_id' => null]);

    $this->artisan('billing:publish-catalog')->assertFailed();
});

it('changes nothing on a dry run', function () {
    $gateway = fakeGateway();
    PlanPrice::query()->update(['dodo_product_id' => null]);

    $this->artisan('billing:publish-catalog', ['--dry-run' => true])->assertSuccessful();

    expect($gateway->published)->toBeEmpty()
        ->and(PlanPrice::whereNull('dodo_product_id')->count())->toBeGreaterThan(0);
});

/*
 * An add-on is a different resource at Dodo, not a product: it carries its own
 * price, inherits the interval of whatever subscription it hangs off, and is
 * attached by an addon id their product endpoints never return. Publishing one
 * as a product would produce an id that looks right and cannot be attached to
 * anything.
 */
it('publishes an add-on as an add-on rather than a product', function () {
    $gateway = fakeGateway();

    $addon = Addon::factory()->quantity()->create(['name' => 'Extra seat']);
    $price = AddonPrice::factory()->for($addon)->create([
        'dodo_addon_id' => null,
        'amount_minor' => 900,
    ]);

    $this->artisan('billing:publish-catalog')->assertSuccessful();

    expect($gateway->publishedAddons)->toHaveCount(1)
        // No interval in the name: it takes the subscription's, so promising
        // one here would be a promise we do not control.
        ->and($gateway->publishedAddons[0]['name'])->toBe('Extra seat')
        ->and($gateway->publishedAddons[0]['amountMinor'])->toBe(900)
        ->and($price->fresh()->dodo_addon_id)->toBe('addon_1')
        ->and($price->fresh()->dodo_product_id)->toBeNull();
});
