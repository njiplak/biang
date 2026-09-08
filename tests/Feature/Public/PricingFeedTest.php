<?php

use App\Contract\Admin\CatalogContract;
use App\Models\Feature;
use App\Models\Plan;
use App\Support\Features;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 11: "Pricing is published by our app and read by the marketing site,
 * so a price change in the admin console updates both places at once. Nobody
 * retypes a price."
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
});

it('publishes the public plans without a login', function () {
    $this->getJson(route('pricing'))
        ->assertOk()
        ->assertJsonPath('plans.0.code', 'starter')
        ->assertJsonPath('plans.1.code', 'pro')
        ->assertJsonStructure([
            'plans' => [['code', 'name', 'is_free', 'prices', 'limits', 'signup_url']],
            'trial_days',
            'signup_url',
        ]);
});

/*
 * There is no free tier to publish. The floor plan exists only so a workspace
 * whose subscription ended has somewhere to rest - it is not public, has no
 * price, and must never appear on a pricing page as something to choose.
 */
it('never publishes the floor plan', function () {
    $response = $this->getJson(route('pricing'))->assertOk();

    expect(collect($response->json('plans'))->pluck('code'))->not->toContain('free')
        ->and($response->json('plans'))->toHaveCount(2);
});

// Every plan on the page is one somebody can actually buy.
it('publishes nothing without a price', function () {
    $response = $this->getJson(route('pricing'))->assertOk();

    foreach ($response->json('plans') as $plan) {
        expect($plan['prices'])->not->toBeEmpty()
            ->and($plan['is_free'])->toBeFalse();
    }
});

it('publishes prices as integer minor units keyed by interval', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();
    $monthly = $pro->prices()->where('billing_interval', 'month')->firstOrFail();

    $this->getJson(route('pricing'))
        ->assertOk()
        ->assertJsonPath('plans.1.prices.month.amount_minor', $monthly->amount_minor)
        ->assertJsonPath('plans.1.prices.month.currency', $monthly->currency)
        ->assertJsonPath('plans.1.prices.year.amount_minor', 49_000);
});

it('publishes the limits so the table needs no retyping', function () {
    $this->getJson(route('pricing'))
        ->assertOk()
        // Starter's seats, straight off plan_features - unit included, so the
        // marketing site can write "5 seats" without knowing the noun.
        ->assertJsonFragment(['key' => 'seats', 'name' => 'Seats', 'unit' => 'seat', 'value' => 5])
        // Plans carry only limits something actually meters, so `projects` is
        // deliberately absent rather than published as a limit nobody enforces.
        ->assertJsonMissing(['key' => 'projects']);
});

/*
 * Section 11 used to describe two buttons and two destinations, one of them
 * "Start free". There is one destination now, because there is no free tier:
 * every plan is bought, and every plan carries its own code through signup.
 */
it('gives each plan the signup url that button should point at', function () {
    $response = $this->getJson(route('pricing'))->assertOk();

    expect($response->json('plans.0.signup_url'))->toBe(route('register', ['plan' => 'starter']))
        ->and($response->json('plans.1.signup_url'))->toBe(route('register', ['plan' => 'pro']));
});

/*
 * The monthly/annual toggle chooses an interval as much as the buttons choose a
 * plan. A link carrying only the plan handed somebody who picked annual a
 * monthly trial, so every price carries its own.
 */
it('gives each price a signup url that names its billing interval', function () {
    $response = $this->getJson(route('pricing'))->assertOk();

    expect($response->json('plans.1.prices.month.signup_url'))
        ->toBe(route('register', ['plan' => 'pro', 'interval' => 'month']))
        ->and($response->json('plans.1.prices.year.signup_url'))
        ->toBe(route('register', ['plan' => 'pro', 'interval' => 'year']));
});

/*
 * The whole point of the seam. A price changed in the admin console has to show
 * up here without a second deploy in the marketing repository.
 */
it('reflects a price change made in the admin console', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();

    app(CatalogContract::class)->addPrice($pro, [
        'billing_interval' => 'month',
        'currency' => 'USD',
        'amount_minor' => 7900,
    ]);

    $this->getJson(route('pricing'))
        ->assertOk()
        ->assertJsonPath('plans.1.prices.month.amount_minor', 7900);
});

it('reflects a limit change made in the admin console', function () {
    $starter = Plan::where('code', 'starter')->firstOrFail();
    $seats = $starter->features()->where('key', 'seats')->firstOrFail();

    app(CatalogContract::class)->syncFeatures($starter, [$seats->id => 4]);

    $this->getJson(route('pricing'))
        ->assertOk()
        ->assertJsonFragment(['key' => 'seats', 'name' => 'Seats', 'unit' => 'seat', 'value' => 4]);
});

// Section 10: retiring a plan must not break the customers on it, but it must
// come off the pricing page.
it('drops a retired plan', function () {
    app(CatalogContract::class)->archivePlan(Plan::where('code', 'pro')->firstOrFail());

    $response = $this->getJson(route('pricing'))->assertOk();

    expect(collect($response->json('plans'))->pluck('code'))->not->toContain('pro');
});

it('never publishes a plan hidden from the pricing page', function () {
    Plan::factory()->hidden()->create(['code' => 'internal', 'name' => 'Internal']);

    $response = $this->getJson(route('pricing'))->assertOk();

    expect(collect($response->json('plans'))->pluck('code'))->not->toContain('internal');
});

it('never publishes an archived price', function () {
    $pro = Plan::where('code', 'pro')->firstOrFail();
    $pro->prices()->where('billing_interval', 'year')->update(['archived_at' => now()]);

    $response = $this->getJson(route('pricing'))->assertOk();

    expect($response->json('plans.1.prices'))->toHaveKey('month')
        ->and($response->json('plans.1.prices'))->not->toHaveKey('year');
});

/*
 * Section 11 puts the app on its own subdomain so the two projects ship
 * independently, which makes every fetch cross-origin.
 */
it('answers a cross origin request from the marketing site', function () {
    // Open by default: this publishes exactly what the pricing page already
    // shows the world, and no endpoint behind CORS here is authenticated.
    $this->getJson(route('pricing'), ['Origin' => 'https://example-marketing.test'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*');
});

it('can be narrowed to the marketing site origin alone', function () {
    config(['cors.allowed_origins' => ['https://example-marketing.test']]);

    $this->getJson(route('pricing'), ['Origin' => 'https://example-marketing.test'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://example-marketing.test');

    // The security property that matters: a disallowed origin must never see
    // its OWN origin echoed back, which is what would let it read the response.
    $header = $this->getJson(route('pricing'), ['Origin' => 'https://somewhere-else.test'])
        ->assertOk()
        ->headers->get('Access-Control-Allow-Origin');

    expect($header)->not->toBe('https://somewhere-else.test')
        ->and($header)->not->toBe('*');
});

it('is cacheable so the marketing site is not on our database', function () {
    $this->getJson(route('pricing'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=300');
});

/*
 * Section 7: null is unlimited, and has to stay null on the wire - a marketing
 * site that receives 0 would advertise the opposite of what we sell.
 *
 * Given its own test because no seeded plan carries an unlimited value any
 * more; borrowing one meant this rule was only covered by accident.
 */
it('publishes an unlimited limit as null rather than zero', function () {
    $plan = Plan::firstWhere('code', 'pro');
    $seats = Feature::firstWhere('key', Features::SEATS);

    $plan->features()->updateExistingPivot($seats->id, ['value' => null]);

    $this->getJson(route('pricing'))
        ->assertOk()
        ->assertJsonFragment(['key' => 'seats', 'value' => null]);
});
