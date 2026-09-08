<?php

use App\Contract\Admin\RevenueContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 10: "Understand the business." Section 15 calls trial-to-paid "the
 * number this whole build exists to move".
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->revenue = app(RevenueContract::class);

    $this->admin = AdminUser::factory()->create();
    $this->admin->assignRole('super-admin');

    $this->pro = Plan::where('code', 'pro')->firstOrFail();
    $this->monthly = $this->pro->prices()->where('billing_interval', 'month')->firstOrFail();

    // A paying, provider-backed subscription on a given price.
    $this->sell = function (PlanPrice $price, array $attributes = []) {
        $workspace = Workspace::factory()->create();

        return Subscription::factory()->for($workspace)->create([
            'plan_id' => $price->plan_id,
            'plan_price_id' => $price->id,
            'status' => SubscriptionStatus::Active,
            ...$attributes,
        ]);
    };
});

it('sums monthly prices into MRR', function () {
    ($this->sell)($this->monthly);
    ($this->sell)($this->monthly);

    $summary = $this->revenue->summary();

    expect($summary['mrr_minor'])->toBe($this->monthly->amount_minor * 2)
        // ARR is MRR annualised, never the sum of annual contracts.
        ->and($summary['arr_minor'])->toBe($this->monthly->amount_minor * 24);
});

// A yearly plan and a monthly one are only comparable once normalised.
it('normalises a yearly price to a month', function () {
    $yearly = $this->pro->prices()->where('billing_interval', BillingInterval::Year)->firstOrFail();

    ($this->sell)($yearly);

    expect($this->revenue->summary()['mrr_minor'])
        ->toBe((int) round($yearly->amount_minor / 12));
});

/*
 * Section 8: a comp has no payment behind it. Counting it would inflate the one
 * number nobody should be able to flatter by granting plans.
 */
it('leaves comped subscriptions out of MRR', function () {
    $workspace = app(WorkspaceContract::class)->create(User::factory()->create(), 'Comped Co');
    app(SubscriptionContract::class)->grantPlan($workspace, $this->monthly, $this->admin, 'Launch partner');

    $summary = $this->revenue->summary();

    expect($summary['mrr_minor'])->toBe(0)
        ->and($summary['comped_count'])->toBe(1);
});

// Section 9 keeps full access while past due, but it is not revenue yet.
it('reports past due separately from MRR', function () {
    ($this->sell)($this->monthly);
    ($this->sell)($this->monthly, ['status' => SubscriptionStatus::PastDue]);

    $summary = $this->revenue->summary();

    expect($summary['mrr_minor'])->toBe($this->monthly->amount_minor)
        ->and($summary['at_risk_minor'])->toBe($this->monthly->amount_minor)
        ->and($summary['at_risk_count'])->toBe(1);
});

it('leaves a running trial out of MRR', function () {
    ($this->sell)($this->monthly, [
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->addDays(10),
    ]);

    $summary = $this->revenue->summary();

    expect($summary['mrr_minor'])->toBe(0)
        ->and($summary['trials']['running'])->toBe(1);
});

/*
 * Measured over the trials that have ENDED, which is when one either converted
 * or did not. A trial still running has not had its chance, so it reads as "no
 * data" rather than as 0% - the same distinction the test below draws for
 * having no trials at all. Counting running trials as misses reported a rate
 * that was wrong by however many trials happened to be open that day.
 */
it('measures trial to paid conversion', function () {
    $workspace = app(WorkspaceContract::class)->create($starter = User::factory()->create(), 'Acme');
    $subscription = app(SubscriptionContract::class)->startTrial($workspace, $this->monthly, $starter);

    expect($this->revenue->summary()['trials'])
        ->toMatchArray([
            'running' => 1,
            'started_total' => 1,
            'ended_total' => 0,
            'converted_total' => 0,
            'conversion_rate' => null,
        ]);

    // Section 4 charges at the end of day 14, so the trial runs out first and
    // the conversion follows. There is no other order.
    $subscription->update(['trial_ends_at' => now()->subHour()]);
    app(SubscriptionContract::class)->convertTrial($subscription);

    expect($this->revenue->summary()['trials'])
        ->toMatchArray([
            'running' => 0,
            'started_total' => 1,
            'ended_total' => 1,
            'converted_total' => 1,
            'conversion_rate' => 100.0,
        ]);
});

// "No data" and "0%" say very different things on a dashboard.
it('reports no conversion data rather than zero percent', function () {
    expect($this->revenue->summary()['trials']['conversion_rate'])->toBeNull();
});

it('measures churn against those still live plus those that left', function () {
    ($this->sell)($this->monthly);
    ($this->sell)($this->monthly);
    ($this->sell)($this->monthly, [
        'status' => SubscriptionStatus::Canceled,
        'canceled_at' => now()->subDays(3),
    ]);

    expect($this->revenue->summary()['churn'])
        ->toMatchArray(['canceled_30d' => 1, 'live' => 2, 'rate' => 33.3]);
});

it('ignores a cancellation older than the window', function () {
    ($this->sell)($this->monthly);
    ($this->sell)($this->monthly, [
        'status' => SubscriptionStatus::Canceled,
        'canceled_at' => now()->subDays(45),
    ]);

    expect($this->revenue->summary()['churn']['canceled_30d'])->toBe(0);
});

it('breaks revenue down by plan', function () {
    $starter = Plan::where('code', 'starter')->firstOrFail();
    $starterPrice = $starter->prices()->where('billing_interval', 'month')->firstOrFail();

    ($this->sell)($this->monthly);
    ($this->sell)($starterPrice);
    ($this->sell)($starterPrice);

    $plans = collect($this->revenue->summary()['plans']);

    // Ordered by MRR, not by customer count: one Pro at 4900 outranks two
    // Starters at 1900 each, which is the distinction that matters when
    // deciding which plan is actually carrying the business.
    expect($plans)->toHaveCount(2)
        ->and($plans->first()['plan'])->toBe('Pro')
        ->and($plans->first()['mrr_minor'])->toBe($this->monthly->amount_minor)
        ->and($plans->firstWhere('plan', 'Starter')['customers'])->toBe(2)
        ->and($plans->firstWhere('plan', 'Starter')['mrr_minor'])
        ->toBe($starterPrice->amount_minor * 2);
});

it('counts signups this week', function () {
    User::factory()->count(2)->create();
    User::factory()->create(['created_at' => now()->subMonth()]);

    $signups = $this->revenue->summary()['signups'];

    expect($signups['users_this_week'])->toBe(2)
        ->and($signups['users_total'])->toBe(3);
});

/*
 * Mixing currencies into one total would be a lie until a rate source exists,
 * so the dashboard says so rather than adding them up silently.
 */
it('flags a mixed currency total instead of adding it up', function () {
    ($this->sell)($this->monthly);
    ($this->sell)(PlanPrice::factory()->for($this->pro)->create([
        'currency' => 'EUR',
        'billing_interval' => BillingInterval::Year,
    ]));

    expect($this->revenue->summary()['currency'])->toBe('MIXED');
});

// ------------------------------------------------------------------- the HTTP

it('shows the figures to staff who may see revenue', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/dashboard')
            ->has('revenue.mrr_minor')
            ->has('revenue.trials')
            ->has('revenue.churn'));
});

// Section 10 puts the money with finance. Support answers tickets.
it('hides the figures from staff without revenue.view', function () {
    $support = AdminUser::factory()->create();
    $support->assignRole('support');

    $this->actingAs($support, 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('revenue', null));
});

it('shows the figures to finance', function () {
    $finance = AdminUser::factory()->create();
    $finance->assignRole('finance');

    $this->actingAs($finance, 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('revenue.mrr_minor'));
});
