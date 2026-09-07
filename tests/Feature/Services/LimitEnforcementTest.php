<?php

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\UsageContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Exceptions\Domain\LimitReached;
use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use App\Support\Features;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 7's pre-flight refusal, as distinct from the workspace-level block.
 *
 * The block is a state a workspace is already IN - computed by evaluate(),
 * enforced by WorkspacePolicy. This stops the write that would put them there,
 * which is the kinder half because nothing has to be undone afterwards.
 *
 * Section 13.1 leaves the value metric open. Every mechanism to enforce ANY
 * metric is here; what is missing is a product that meters one.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->entitlements = app(EntitlementContract::class);
    $this->usage = app(UsageContract::class);

    $this->workspace = app(WorkspaceContract::class)->create(User::factory()->create(), 'Acme Inc');
});

/**
 * Puts a limit for $key on the plan this workspace is on, then rebuilds the
 * snapshot the enforcement layer actually reads.
 */
function limitFreePlanTo(string $key, ?int $value): void
{
    $plan = Plan::firstWhere('is_free', true);
    $feature = Feature::firstWhere('key', $key);

    $plan->features()->syncWithoutDetaching([$feature->id => ['value' => $value]]);
    $plan->features()->updateExistingPivot($feature->id, ['value' => $value]);

    app(EntitlementContract::class)->rebuild(test()->workspace->fresh());
}

it('allows a write that stays inside the limit', function () {
    // Free plan carries 2 seats.
    expect(fn () => $this->entitlements->assertAllows($this->workspace, 'seats', 2))
        ->not->toThrow(LimitReached::class);
});

it('refuses the write that would breach the limit', function () {
    expect(fn () => $this->entitlements->assertAllows($this->workspace, 'seats', 3))
        ->toThrow(LimitReached::class);
});

// Section 7: "we tell them exactly how many".
it('names the limit in the message', function () {
    try {
        $this->entitlements->assertAllows($this->workspace, 'seats', 3);
        $this->fail('Expected LimitReached.');
    } catch (LimitReached $e) {
        expect($e->limit)->toBe(2)
            ->and($e->featureKey)->toBe('seats')
            ->and($e->userMessage())->toContain('all 2 of your seats');
    }
});

it('never refuses an unlimited feature', function () {
    $seats = Feature::where('key', 'seats')->firstOrFail();

    $this->entitlements->override(
        $this->workspace, $seats, null, AdminUser::factory()->create(), 'Comped',
    );

    expect(fn () => $this->entitlements->assertAllows($this->workspace, 'seats', 10_000))
        ->not->toThrow(LimitReached::class);
});

/*
 * A missing entitlement is a denial, not a free pass - treating an unknown key
 * as unlimited would turn a typo into unmetered capacity. But the message must
 * not claim their limit is zero.
 */
it('refuses a feature the plan does not carry, without inventing a number', function () {
    try {
        $this->entitlements->assertAllows($this->workspace, 'not-a-feature', 1);
        $this->fail('Expected LimitReached.');
    } catch (LimitReached $e) {
        expect($e->limit)->toBeNull()
            ->and($e->userMessage())->toContain('does not include');
    }
});

// The whole point of the seam: a staff override reaches it with no extra code.
it('honours a staff override raised above the plan', function () {
    $seats = Feature::where('key', 'seats')->firstOrFail();

    expect(fn () => $this->entitlements->assertAllows($this->workspace, 'seats', 20))
        ->toThrow(LimitReached::class);

    $this->entitlements->override(
        $this->workspace, $seats, 50, AdminUser::factory()->create(), 'Pilot',
    );

    expect(fn () => $this->entitlements->assertAllows($this->workspace, 'seats', 20))
        ->not->toThrow(LimitReached::class);
});

// ------------------------------------------------- what is actually metered

/*
 * Section 13.1. The plan table carries limits for candidate value metrics that
 * nothing meters yet. A limit on an unmeasured key can never be breached -
 * usage stays at zero and evaluate() never flags it - so the plan reads as
 * enforced when it is decorative.
 *
 * This test is the reminder: when a metric starts being measured, add its key
 * to Features::MEASURED and this will tell you if you forgot.
 */
it('only claims to measure what something actually writes', function () {
    $seeded = Feature::query()->pluck('key');

    // Seeded, limited on every plan, and metered by nothing.
    expect($seeded)->toContain('projects', 'api_calls')
        ->and(Features::isMeasured('projects'))->toBeFalse()
        ->and(Features::isMeasured('api_calls'))->toBeFalse()
        // Seats is the one real metric today: MembershipService writes it.
        ->and(Features::isMeasured(Features::SEATS))->toBeTrue();
});

it('confirms an unmeasured limit can never fire', function () {
    // A limit on a key nothing increments, which is exactly why PlanSeeder no
    // longer ships one: usage stays at zero, so the limit reads as enforced
    // while being decorative.
    limitFreePlanTo('projects', 3);

    $this->usage->evaluate($this->workspace);

    expect($this->usage->current($this->workspace, 'projects'))->toBe(0)
        ->and($this->workspace->fresh()->isOverLimit())->toBeFalse();
});

/*
 * And the counterpart: the moment something DOES meter a key, the existing
 * machinery blocks on it with no further code. This is what section 13.1 is
 * actually waiting on - a product, not a decision.
 */
it('blocks on any metric the moment something meters it', function () {
    // The limit is attached HERE rather than read off the seeder. Plans ship
    // only measured limits now, so a test that borrowed a decorative one was
    // really asserting the seeder's contents, not the machinery.
    limitFreePlanTo('projects', 3);

    $this->usage->setGauge($this->workspace, 'projects', 4);
    $this->usage->evaluate($this->workspace);

    expect($this->workspace->fresh()->isOverLimit())->toBeTrue()
        ->and($this->workspace->fresh()->over_limit_features)->toContain('projects')
        ->and($this->workspace->fresh()->canWrite())->toBeFalse();

    expect(fn () => $this->entitlements->assertAllows($this->workspace, 'projects', 4))
        ->toThrow(LimitReached::class);
});
