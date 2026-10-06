<?php

use App\Contract\Admin\RevenueContract;
use App\Contract\Billing\CatalogPublisherContract;
use App\Enums\BillingInterval;
use App\Enums\BillingSource;
use App\Enums\SubscriptionStatus;
use App\Models\Addon;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Workspace;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

/*
 * A lifetime price is paid once. At Dodo that is a one-time product rather
 * than a recurring one, plus a pay-what-you-want "upgrade" product that a
 * lifetime customer moving up a tier pays the difference through.
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

it('publishes a lifetime price as a one-time product and an upgrade product', function () {
    $gateway = fakeGateway();

    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.catalog.plan.price.store', $this->plan), [
            'billing_interval' => 'lifetime',
            'currency' => 'USD',
            'amount_minor' => 29_900,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $price = PlanPrice::where('plan_id', $this->plan->id)
        ->where('billing_interval', 'lifetime')->firstOrFail();

    expect($price->billing_interval)->toBe(BillingInterval::Lifetime)
        ->and($price->dodo_product_id)->toBe('prod_1')
        ->and($price->dodo_upgrade_product_id)->toBe('upgrade_1')
        ->and($gateway->published)->toHaveCount(1)
        ->and($gateway->published[0]['name'])->toBe('Pro (Lifetime)')
        ->and($gateway->published[0]['interval'])->toBe('lifetime')
        ->and($gateway->published[0]['amountMinor'])->toBe(29_900)
        ->and($gateway->publishedUpgrades)->toHaveCount(1)
        ->and($gateway->publishedUpgrades[0]['name'])->toBe('Pro (Lifetime upgrade)')
        ->and($gateway->publishedUpgrades[0]['currency'])->toBe('USD');
});

// A recurring price has nothing to upgrade through - only lifetime prices do.
it('publishes no upgrade product for a recurring price', function () {
    $gateway = fakeGateway();

    $price = PlanPrice::factory()->for($this->plan)->create([
        'billing_interval' => BillingInterval::Month,
        'currency' => 'EUR',
    ]);

    app(CatalogPublisherContract::class)->publish($price);

    expect($price->fresh()->dodo_upgrade_product_id)->toBeNull()
        ->and($gateway->publishedUpgrades)->toBeEmpty();
});

/*
 * The two products are separate calls. If the second is refused the first
 * must not be minted again on the retry - a second product at their end would
 * split one price's sales across two ids.
 */
it('finishes a half-published lifetime price without republishing the half that worked', function () {
    $gateway = fakeGateway();

    $price = PlanPrice::factory()->for($this->plan)->create([
        'billing_interval' => BillingInterval::Lifetime,
        'dodo_product_id' => 'prod_already',
        'dodo_upgrade_product_id' => null,
    ]);

    app(CatalogPublisherContract::class)->publish($price);

    expect($price->fresh()->dodo_product_id)->toBe('prod_already')
        ->and($price->fresh()->dodo_upgrade_product_id)->toBe('upgrade_1')
        ->and($gateway->published)->toBeEmpty();
});

it('counts a lifetime price missing its upgrade product as unpublished', function () {
    fakeGateway();

    $price = PlanPrice::factory()->for($this->plan)->create([
        'billing_interval' => BillingInterval::Lifetime,
        'dodo_product_id' => 'prod_lt',
        'dodo_upgrade_product_id' => null,
    ]);

    $unpublished = app(CatalogPublisherContract::class)->unpublished();

    expect($unpublished['plan']->pluck('id')->all())->toContain($price->id);

    $this->artisan('billing:publish-catalog')->assertSuccessful();

    expect($price->fresh()->dodo_upgrade_product_id)->not->toBeNull();
});

it('shows a lifetime price as not on sale until both products exist', function () {
    fakeGateway();

    PlanPrice::factory()->for($this->plan)->create([
        'billing_interval' => BillingInterval::Lifetime,
        'dodo_product_id' => 'prod_lt',
        'dodo_upgrade_product_id' => null,
    ]);

    $this->actingAs($this->staff, 'admin')
        ->get(route('admin.catalog.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.2.prices.2.interval', 'lifetime')
            ->where('plans.2.prices.2.is_published', false));
});

it('retires both provider products when a lifetime price is archived', function () {
    $gateway = fakeGateway();

    $price = PlanPrice::factory()->for($this->plan)->create([
        'billing_interval' => BillingInterval::Lifetime,
        'dodo_product_id' => 'prod_lt',
        'dodo_upgrade_product_id' => 'upgrade_lt',
    ]);

    $this->actingAs($this->staff, 'admin')
        ->delete(route('admin.catalog.plan.price.archive', [$this->plan, $price]))
        ->assertRedirect();

    expect($gateway->archived)->toContain('prod_lt')
        ->and($gateway->archived)->toContain('upgrade_lt');
});

// An add-on is charged on the subscription it hangs off, and a lifetime plan
// has no subscription for it to hang off.
it('refuses a lifetime price for an add-on', function () {
    fakeGateway();

    $addon = Addon::factory()->quantity()->create();

    $this->actingAs($this->staff, 'admin')
        ->post(route('admin.catalog.addon.price.store', $addon), [
            'billing_interval' => 'lifetime',
            'currency' => 'USD',
            'amount_minor' => 900,
        ])
        ->assertSessionHasErrors('billing_interval');

    expect($addon->prices()->count())->toBe(0);
});

it('lists a lifetime price on the public pricing feed with no trial', function () {
    PlanPrice::factory()->for($this->plan)->create([
        'billing_interval' => BillingInterval::Lifetime,
        'amount_minor' => 29_900,
    ]);

    $this->getJson(route('pricing'))
        ->assertOk()
        ->assertJsonPath('plans.1.prices.lifetime.amount_minor', 29_900)
        ->assertJsonPath('plans.1.prices.lifetime.trial_days', null);
});

/*
 * MRR is recurring revenue. A lifetime sale recurs never, so counting it at
 * any monthly figure would invent revenue that will not come again.
 */
it('keeps lifetime sales out of recurring revenue and counts them separately', function () {
    $monthly = $this->plan->prices()->where('billing_interval', 'month')->firstOrFail();
    $lifetime = PlanPrice::factory()->for($this->plan)->create([
        'billing_interval' => BillingInterval::Lifetime,
        'amount_minor' => 29_900,
    ]);

    Subscription::factory()->for(Workspace::factory())->create([
        'plan_id' => $this->plan->id,
        'plan_price_id' => $monthly->id,
        'status' => SubscriptionStatus::Active,
    ]);

    Subscription::factory()->for(Workspace::factory())->create([
        'plan_id' => $this->plan->id,
        'plan_price_id' => $lifetime->id,
        'status' => SubscriptionStatus::Active,
        'billing_source' => BillingSource::DodoOneTime,
        'dodo_subscription_id' => null,
        'dodo_payment_id' => 'pay_lt_1',
        'current_period_end' => null,
    ]);

    $summary = app(RevenueContract::class)->summary();

    expect($summary['mrr_minor'])->toBe($monthly->amount_minor)
        ->and($summary['lifetime_count'])->toBe(1);
});
