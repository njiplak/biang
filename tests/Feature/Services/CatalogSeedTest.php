<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Support\Features;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
});

it('seeds exactly one free plan', function () {
    expect(Plan::where('is_free', true)->count())->toBe(1);
});

it('seeds a seats feature, which section 7 needs regardless of the value metric', function () {
    expect(Feature::where('key', Features::SEATS)->exists())->toBeTrue();
});

it('gives the free plan a real seat allowance', function () {
    $free = Plan::where('is_free', true)->with('features')->first();

    expect($free->limitFor(Features::SEATS))->toBeGreaterThan(0);
});

it('gives every non-free plan at least one active price', function () {
    Plan::where('is_free', false)->get()->each(function (Plan $plan) {
        expect($plan->prices()->whereNull('archived_at')->count())
            ->toBeGreaterThan(0, "plan {$plan->code} has no active price");
    });
});

it('prices everything in whole minor units', function () {
    PlanPrice::all()->each(function (PlanPrice $price) {
        expect($price->amount_minor)->toBeInt()
            ->and($price->amount_minor)->toBeGreaterThanOrEqual(0);
    });
});

// Seeders run repeatedly in real deployments, and a duplicate free plan would
// trip the plans_single_free_plan index.
it('is idempotent', function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    expect(Plan::where('is_free', true)->count())->toBe(1)
        ->and(Feature::where('key', Features::SEATS)->count())->toBe(1);
});

it('seeds a staff account that is active and can administer the console', function () {
    $this->seed(Database\Seeders\AdminRoleSeeder::class);
    $this->seed(AdminUserSeeder::class);

    expect(AdminUser::count())->toBeGreaterThan(0)
        ->and(AdminUser::first()->is_active)->toBeTrue()
        ->and(AdminUser::first()->hasRole('super-admin'))->toBeTrue();
});

// The end-to-end proof: with only the seeded catalog in place, the whole stack
// works - workspace creation, entitlement resolution, a manual grant and a
// cancellation back to free.
it('supports the full lifecycle on seeded data alone', function () {
    $this->seed(AdminUserSeeder::class);

    $user = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create($user, 'Acme Inc');

    expect($workspace->seatsUsed())->toBe(1)
        ->and($workspace->canWrite())->toBeTrue();

    $paidPrice = PlanPrice::whereHas('plan', fn ($q) => $q->where('is_free', false))->first();
    $subscription = app(SubscriptionContract::class)
        ->grantPlan($workspace, $paidPrice, AdminUser::first(), 'Seeded lifecycle check');

    expect($subscription->plan->is_free)->toBeFalse();

    app(SubscriptionContract::class)->cancel($workspace);

    expect($workspace->fresh()->billing_status->value)->toBe('free')
        ->and($workspace->fresh()->canRead())->toBeTrue();
});
