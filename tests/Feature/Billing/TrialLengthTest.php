<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Workspace\OnboardingController;
use App\Models\AdminUser;
use App\Models\NotificationLog;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Service\Billing\SubscriptionService;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

/*
 * The trial length is BILLING_TRIAL_DAYS, read through config('billing.trial_days').
 * 0 switches trials off: every price is then bought outright, exactly as annual
 * already is, so each screen that quotes a trial has to stop quoting one.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    PlanPrice::query()->whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->get()
        ->each(fn (PlanPrice $price) => $price->update([
            'dodo_product_id' => 'prod_pro_'.$price->billing_interval->value,
        ]));

    $this->monthly = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();
    $this->annual = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'year')->firstOrFail();

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

// ------------------------------------------------------------ reading it

it('sells monthly with the configured number of free days', function () {
    config(['billing.trial_days' => 7]);

    expect(SubscriptionService::trialLength())->toBe(7)
        ->and(SubscriptionService::trialDaysFor($this->monthly))->toBe(7)
        ->and(SubscriptionService::trialDaysFor($this->annual))->toBeNull();
});

// env() hands back a string for anything set in .env.
it('reads the number the way env delivers it', function () {
    config(['billing.trial_days' => '7']);

    expect(SubscriptionService::trialLength())->toBe(7);
});

it('switches trials off at zero', function (int|string $off) {
    config(['billing.trial_days' => $off]);

    expect(SubscriptionService::trialLength())->toBeNull()
        ->and(SubscriptionService::trialDaysFor($this->monthly))->toBeNull()
        ->and(SubscriptionService::trialDaysFor($this->annual))->toBeNull();
})->with([0, '0']);

it('accepts the shortest trial that still gets every warning', function () {
    config(['billing.trial_days' => 4]);

    expect(SubscriptionService::trialLength())->toBe(4);
});

/*
 * Refused loudly rather than read as something. A typo cast to an int is 0,
 * which would switch trials off without anybody having asked for that.
 */
it('refuses a value it cannot trust', function (mixed $value) {
    config(['billing.trial_days' => $value]);

    expect(fn () => SubscriptionService::trialLength())
        ->toThrow(UnexpectedValueException::class, 'BILLING_TRIAL_DAYS');
})->with([
    'not a number' => ['abc'],
    'empty' => [''],
    'fractional' => ['7.5'],
    'negative' => [-1],
    'null' => [null],
    'too short for the 3-day warning' => [3],
    'one day' => [1],
]);

// ------------------------------------------------------- trials switched off

it('advertises no trial on the pricing feed', function () {
    config(['billing.trial_days' => 0]);

    $this->getJson(route('pricing'))
        ->assertOk()
        ->assertJsonPath('trial_days', null)
        ->assertJsonPath('plans.1.prices.month.trial_days', null)
        ->assertJsonPath('plans.1.prices.year.trial_days', null);
});

it('offers no trial on the billing page', function () {
    config(['billing.trial_days' => 0]);

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $prices = collect($page->toArray()['props']['plans'])->firstWhere('code', 'pro')['prices'];

            expect(collect($prices)->firstWhere('interval', 'month')['trial_days'])->toBeNull();
        });
});

// A page loaded before the switch still has the button on it.
it('refuses a trial posted from a stale page, saying trials are off', function () {
    $gateway = fakeGateway();
    config(['billing.trial_days' => 0]);

    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => $this->monthly->id])
        ->assertSessionHasErrors(['errors' => 'Free trials are not available right now. You can subscribe to this plan directly.']);

    expect($gateway->checkouts)->toBeEmpty();
});

it('sends a monthly signup straight to a paid checkout', function () {
    $gateway = fakeGateway();
    config(['billing.trial_days' => 0]);

    $this->get(route('register', ['plan' => 'pro']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('plan.interval', 'month')
            ->where('plan.trial_days', null));

    $this->post(route('register.store'), [
        'name' => 'Jo',
        'email' => 'jo@example.com',
        'password' => 'Str0ng-password!',
        'password_confirmation' => 'Str0ng-password!',
    ])->assertRedirect();

    $buyer = User::where('email', 'jo@example.com')->firstOrFail();
    $buyer->markEmailAsVerified();
    app('auth')->forgetGuards();

    $this->post(route('workspace.store'), ['name' => 'Jo Inc'])
        ->assertRedirect($gateway->checkoutUrl);

    // Charged at checkout, and no trial claim staked against a trial that is not happening.
    expect($gateway->checkouts)->toHaveCount(1)
        ->and($gateway->checkouts[0]['plan_price_id'])->toBe($this->monthly->id)
        ->and($gateway->checkouts[0]['trial_period_days'])->toBeNull()
        ->and($buyer->fresh()->trial_checkout_at)->toBeNull();
});

// The one-trial rule guards a trial. With none on offer there is nothing to guard.
it('still sells monthly to somebody who has already used their trial', function () {
    $gateway = fakeGateway();
    config(['billing.trial_days' => 0]);
    $returning = User::factory()->create(['trial_consumed_at' => now()->subMonth()]);

    $this->actingAs($returning)
        ->withSession([RegisterController::PENDING_PLAN => 'pro', RegisterController::PENDING_INTERVAL => 'month'])
        ->post(route('workspace.store'), ['name' => 'Returning Inc'])
        ->assertRedirect($gateway->checkoutUrl);

    expect($gateway->checkouts)->toHaveCount(1)
        ->and($gateway->checkouts[0]['trial_period_days'])->toBeNull();
});

it('stops promising a trial in the pending plan prompt', function () {
    config(['billing.trial_days' => 0]);
    $newcomer = User::factory()->create();

    $this->actingAs($newcomer)
        ->withSession([OnboardingController::FAILED => true, RegisterController::PENDING_PLAN => 'pro'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pending_plan.name', 'Pro')
            ->where('pending_plan.trial_days', null));
});

/*
 * Only new checkouts read the setting. A trial already running keeps the
 * charge date it was sold with, its warnings, and staff's ability to extend it.
 */
it('leaves a trial that is already running alone', function () {
    // Before anything resolves the service: the factory's trial is held with Dodo.
    $gateway = fakeGateway();
    Notification::fake();
    config(['billing.trial_days' => 0]);

    $subscription = Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->monthly->plan_id,
        'plan_price_id' => $this->monthly->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->addDays(3),
    ]);

    $this->artisan('billing:trial-warnings')->assertSuccessful();

    expect(NotificationLog::where('type', 'trial_ending_3d')->count())->toBe(1);

    $extended = app(SubscriptionContract::class)
        ->extendTrial($this->workspace, 7, AdminUser::factory()->create(), 'Still evaluating');

    expect($extended->status)->toBe(SubscriptionStatus::Trialing)
        ->and($extended->trial_ends_at->toDateString())->toBe(now()->addDays(10)->toDateString())
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Trialing)
        ->and($gateway->trialExtensions)->toHaveCount(1);
});

// ------------------------------------------------------------- a custom length

it('sells the configured length at checkout and on the pricing feed', function () {
    $gateway = fakeGateway();
    config(['billing.trial_days' => 7]);

    $this->getJson(route('pricing'))
        ->assertOk()
        ->assertJsonPath('trial_days', 7)
        ->assertJsonPath('plans.1.prices.month.trial_days', 7);

    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => $this->monthly->id])
        ->assertRedirect($gateway->checkoutUrl);

    expect($gateway->checkouts[0]['trial_period_days'])->toBe(7);
});

/*
 * Why the floor is 4 and not 3. Warnings go out once a day at 09:00, so a trial
 * started after that run is first seen the next morning with a day fewer left.
 * At 4 days that first look is exactly the 3-day warning.
 */
it('gets both required warnings at the minimum length when started after the morning run', function () {
    Notification::fake();
    config(['billing.trial_days' => 4]);

    $this->travelTo(Carbon::parse('2026-10-12 10:00'));
    Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->monthly->plan_id,
        'plan_price_id' => $this->monthly->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->addDays(SubscriptionService::trialLength()),
    ]);

    $this->travelTo(Carbon::parse('2026-10-13 09:00'));
    $this->artisan('billing:trial-warnings')->assertSuccessful();

    $this->travelTo(Carbon::parse('2026-10-15 09:00'));
    $this->artisan('billing:trial-warnings')->assertSuccessful();

    expect(NotificationLog::where('type', 'trial_ending_3d')->count())->toBe(1)
        ->and(NotificationLog::where('type', 'trial_ending_1d')->count())->toBe(1);
});
