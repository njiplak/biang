<?php

use App\Contract\Admin\RevenueContract;
use App\Enums\DunningResolution;
use App\Enums\SubscriptionStatus;
use App\Models\AdminUser;
use App\Models\DunningState;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 15 asks for movement, not a snapshot: "trial to paid conversion - the
 * number this whole build exists to move", and "monthly churn, and how much of
 * it is voluntary versus failed payments". The dashboard reported one figure
 * per metric over a fixed window, so none of it could be seen moving.
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

    /** A trial that ENDED at a given time, with or without a card behind it. */
    $this->trial = function (\DateTimeInterface $endedAt, bool $withCard) {
        return Subscription::factory()->for(Workspace::factory()->create())->create([
            'plan_id' => $this->monthly->plan_id,
            'plan_price_id' => $this->monthly->id,
            'trial_ends_at' => $endedAt,
            'dodo_subscription_id' => $withCard ? 'sub_'.uniqid() : null,
            'status' => $withCard ? SubscriptionStatus::Active : SubscriptionStatus::Canceled,
            'canceled_at' => $withCard ? null : $endedAt,
        ]);
    };

    $this->months = fn () => collect($this->revenue->summary()['trends'])->keyBy('month');
});

// ------------------------------------------------------------- the shape

it('reports twelve months ending with this one', function () {
    $trends = $this->revenue->summary()['trends'];

    expect($trends)->toHaveCount(12)
        ->and($trends[11]['month'])->toBe(now()->format('Y-m'))
        ->and($trends[0]['month'])->toBe(now()->subMonths(11)->format('Y-m'));
});

it('reports a quiet month as zeroes rather than leaving a gap', function () {
    $month = ($this->months)()->get(now()->subMonths(4)->format('Y-m'));

    expect($month)->not->toBeNull()
        ->and($month['signups_users'])->toBe(0)
        ->and($month['churn_voluntary'])->toBe(0)
        // Nothing to divide: a month with no ended trials has no rate, not 0%.
        ->and($month['conversion_rate'])->toBeNull();
});

// ---------------------------------------------------------------- signups

it('buckets signups by the month they happened in', function () {
    User::factory()->create(['created_at' => now()->subMonths(2)]);
    User::factory()->count(2)->create(['created_at' => now()]);
    Workspace::factory()->create(['created_at' => now()->subMonths(2)]);

    $months = ($this->months)();

    expect($months[now()->subMonths(2)->format('Y-m')]['signups_users'])->toBe(1)
        ->and($months[now()->subMonths(2)->format('Y-m')]['signups_workspaces'])->toBe(1)
        ->and($months[now()->format('Y-m')]['signups_users'])->toBe(2);
});

it('leaves out anything older than the window', function () {
    User::factory()->create(['created_at' => now()->subMonths(18)]);

    expect(collect($this->revenue->summary()['trends'])->sum('signups_users'))->toBe(0);
});

// ------------------------------------------------------------- conversion

/*
 * Bucketed by the month the trial ENDED, which is when it either converted or
 * did not. A trial still running has not had its chance yet, so counting it as
 * a miss would understate the rate for the current month, every month.
 */
it('measures conversion over the cohort that could actually convert', function () {
    $ended = now()->subMonth();

    ($this->trial)($ended, withCard: true);
    ($this->trial)($ended, withCard: false);

    $month = ($this->months)()->get($ended->format('Y-m'));

    expect($month['trials_ended'])->toBe(2)
        ->and($month['trials_converted'])->toBe(1)
        ->and($month['conversion_rate'])->toBe(50.0);
});

it('does not count a trial that is still running', function () {
    Subscription::factory()->for(Workspace::factory()->create())->create([
        'plan_id' => $this->monthly->plan_id,
        'plan_price_id' => $this->monthly->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->addDays(5),
    ]);

    expect(($this->months)()->get(now()->format('Y-m'))['trials_ended'])->toBe(0);
});

/*
 * The rule has to survive what happens NEXT. Counting "is active today" makes
 * a converted trial stop counting the moment that customer later cancels, so
 * last year's conversion rate quietly falls every time somebody churns.
 */
it('keeps counting a conversion after that customer later cancels', function () {
    $ended = now()->subMonths(3);

    $subscription = ($this->trial)($ended, withCard: true);
    $subscription->update([
        'status' => SubscriptionStatus::Canceled,
        'canceled_at' => now()->subMonth(),
    ]);

    expect(($this->months)()->get($ended->format('Y-m'))['conversion_rate'])->toBe(100.0);
});

// ------------------------------------------------------------------ churn

/*
 * Section 15: "how much of it is voluntary versus failed payments". They are
 * recorded in two different places - a customer who leaves cancels the
 * subscription, while a customer whose card fails is suspended by
 * ExpireDunningGrace and never has canceled_at set at all.
 */
it('splits churn into the two ways a customer leaves', function () {
    $when = now()->subMonth();

    // Voluntary: converted, then cancelled later.
    $left = ($this->trial)(now()->subMonths(3), withCard: true);
    $left->update(['status' => SubscriptionStatus::Canceled, 'canceled_at' => $when]);

    // Involuntary: the grace period ran out.
    $paying = ($this->trial)(now()->subMonths(3), withCard: true);
    DunningState::factory()->create([
        'workspace_id' => $paying->workspace_id,
        'subscription_id' => $paying->id,
        'resolved_at' => $when,
        'resolution' => DunningResolution::Suspended,
    ]);

    $month = ($this->months)()->get($when->format('Y-m'));

    expect($month['churn_voluntary'])->toBe(1)
        ->and($month['churn_involuntary'])->toBe(1);
});

/*
 * A trial that ends without a card is cancelled by ConvertEndedTrials, which
 * sets canceled_at exactly like a customer leaving. It is a failed conversion,
 * already counted as one above - counting it as churn too would report a
 * customer we never had as a customer we lost.
 */
it('does not count a lapsed trial as churn', function () {
    $ended = now()->subMonth();

    ($this->trial)($ended, withCard: false);

    expect(($this->months)()->get($ended->format('Y-m'))['churn_voluntary'])->toBe(0);
});

it('does not count a recovered payment as churn', function () {
    $when = now()->subMonth();
    $paying = ($this->trial)(now()->subMonths(3), withCard: true);

    DunningState::factory()->create([
        'workspace_id' => $paying->workspace_id,
        'subscription_id' => $paying->id,
        'resolved_at' => $when,
        'resolution' => DunningResolution::Recovered,
    ]);

    expect(($this->months)()->get($when->format('Y-m'))['churn_involuntary'])->toBe(0);
});

// -------------------------------------------------- failed-payment recovery

it('measures how much failed payment we win back', function () {
    $paying = ($this->trial)(now()->subMonths(3), withCard: true);

    foreach ([DunningResolution::Recovered, DunningResolution::Recovered, DunningResolution::Suspended] as $resolution) {
        DunningState::factory()->create([
            'workspace_id' => $paying->workspace_id,
            'subscription_id' => $paying->id,
            'resolved_at' => now()->subMonth(),
            'resolution' => $resolution,
        ]);
    }

    expect($this->revenue->summary()['recovery'])
        ->toMatchArray(['resolved' => 3, 'recovered' => 2, 'rate' => 66.7]);
});

it('reports no recovery data rather than zero percent', function () {
    expect($this->revenue->summary()['recovery']['rate'])->toBeNull();
});

// An episode still open has not been won or lost yet.
it('leaves an unresolved episode out of the recovery rate', function () {
    $paying = ($this->trial)(now()->subMonths(3), withCard: true);

    DunningState::factory()->create([
        'workspace_id' => $paying->workspace_id,
        'subscription_id' => $paying->id,
        'resolved_at' => null,
        'resolution' => null,
    ]);

    expect($this->revenue->summary()['recovery'])
        ->toMatchArray(['resolved' => 0, 'rate' => null]);
});

// ------------------------------------------------------------------ access

it('serves the trends to the dashboard', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('revenue.trends', 12)
            ->has('revenue.recovery')
            // The chart reads these two off every bucket; a missing key is a
            // blank axis rather than an error, so it is worth pinning.
            ->has('revenue.trends.0.label')
            ->has('revenue.trends.0.conversion_rate'));
});

it('keeps the trends behind the revenue permission', function () {
    $support = AdminUser::factory()->create();
    $support->assignRole('support');

    $this->actingAs($support, 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('revenue', null));
});
