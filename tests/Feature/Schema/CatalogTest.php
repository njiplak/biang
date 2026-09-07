<?php

use App\Enums\AddonKind;
use App\Enums\BillingInterval;
use App\Enums\FeatureType;
use App\Models\Addon;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Database\QueryException;

it('casts catalog enums', function () {
    $plan = Plan::factory()->create();
    $price = PlanPrice::factory()->for($plan)->create();
    $feature = Feature::factory()->create();
    $addon = Addon::factory()->create();

    expect($price->billing_interval)->toBeInstanceOf(BillingInterval::class)
        ->and($feature->type)->toBeInstanceOf(FeatureType::class)
        ->and($addon->kind)->toBeInstanceOf(AddonKind::class);
});

// Section 10: retire a plan without breaking customers already on it. Editing a
// price INSERTs a new row and archives the old one, so a subscription that
// points at the old row is untouched.
it('keeps archived prices alongside the active one', function () {
    $plan = Plan::factory()->create();
    $old = PlanPrice::factory()->for($plan)->archived()->create(['amount_minor' => 2900]);
    $new = PlanPrice::factory()->for($plan)->create([
        'amount_minor' => 3900,
        'billing_interval' => $old->billing_interval,
        'currency' => $old->currency,
    ]);

    expect($plan->prices()->count())->toBe(2)
        ->and($plan->activePriceFor($new->billing_interval, $new->currency)->id)->toBe($new->id);
});

it('refuses two active prices for the same plan, interval and currency', function () {
    $plan = Plan::factory()->create();
    PlanPrice::factory()->for($plan)->create([
        'billing_interval' => BillingInterval::Month,
        'currency' => 'USD',
    ]);

    expect(fn () => PlanPrice::factory()->for($plan)->create([
        'billing_interval' => BillingInterval::Month,
        'currency' => 'USD',
    ]))->toThrow(QueryException::class);
});

it('allows the same interval in a different currency', function () {
    $plan = Plan::factory()->create();
    PlanPrice::factory()->for($plan)->create(['billing_interval' => BillingInterval::Month, 'currency' => 'USD']);
    PlanPrice::factory()->for($plan)->create(['billing_interval' => BillingInterval::Month, 'currency' => 'IDR']);

    expect($plan->prices()->count())->toBe(2);
});

// Cancelling drops a workspace to the free tier (section 6), so "which free
// plan" must never be ambiguous.
it('permits only one free plan', function () {
    Plan::factory()->free()->create();

    expect(fn () => Plan::factory()->free()->create())->toThrow(QueryException::class);
});

it('excludes archived plans from the active scope', function () {
    Plan::factory()->count(2)->create();
    Plan::factory()->archived()->create();

    expect(Plan::active()->count())->toBe(2);
});

// Section 13.1's value metric is still open. It becomes a row here, not a
// column, which is why nothing about the schema is blocked on it.
it('attaches limits to plans as data with null meaning unlimited', function () {
    $plan = Plan::factory()->create();
    $seats = Feature::factory()->create(['key' => 'seats']);
    $projects = Feature::factory()->create(['key' => 'projects']);

    $plan->features()->attach($seats, ['value' => 5]);
    $plan->features()->attach($projects, ['value' => null]);

    expect($plan->limitFor('seats'))->toBe(5)
        ->and($plan->limitFor('projects'))->toBeNull()
        ->and($plan->limitFor('nonexistent'))->toBeNull();
});

it('links purchasable add-ons to a plan', function () {
    $plan = Plan::factory()->create();
    $seats = Feature::factory()->create(['key' => 'seats']);
    $addon = Addon::factory()->quantity()->for($seats)->create(['grant_per_unit' => 1]);

    $plan->addons()->attach($addon);

    expect($plan->addons)->toHaveCount(1)
        ->and($plan->addons->first()->feature->key)->toBe('seats');
});
