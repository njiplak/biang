<?php

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Billing\UsageContract;
use App\Enums\EntitlementSource;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\TrialNotExtendable;
use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlementOverride;

/*
 * Section 10: "Close a deal / rescue a customer. Grant a plan by hand. Extend a
 * trial. Override a limit for one specific customer. Sales cannot wait for a
 * deploy."
 */

beforeEach(function () {
    $this->subscriptions = app(SubscriptionContract::class);
    $this->entitlements = app(EntitlementContract::class);
    $this->usage = app(UsageContract::class);

    $this->seats = Feature::factory()->create(['key' => 'seats']);
    $this->freePlan = Plan::factory()->free()->create();
    $this->freePlan->features()->attach($this->seats, ['value' => 2]);

    $this->admin = AdminUser::factory()->create();
});

// ------------------------------------------------------------- extend a trial

it('adds days on top of a trial that is still running', function () {
    $workspace = Workspace::factory()->create();
    $price = PlanPrice::factory()->for(Plan::factory()->create())->create();

    $subscription = Subscription::factory()->for($workspace)->create([
        'plan_id' => $price->plan_id,
        'plan_price_id' => $price->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->addDays(3),
    ]);

    $extended = $this->subscriptions->extendTrial($workspace, 7, $this->admin, 'Evaluating with their security team');

    // 3 days left + 7 granted, not 7 from today.
    expect($extended->trial_ends_at->toDateString())->toBe(now()->addDays(10)->toDateString())
        ->and($extended->granted_by_admin_id)->toBe($this->admin->id)
        ->and($extended->grant_reason)->toBe('Evaluating with their security team')
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Trialing);
});

/*
 * The converter runs hourly, so there is a window where a trial has lapsed but
 * the subscription is still Trialing. Extending from the stale date would land
 * in the past and change nothing.
 */
it('restarts from today when the trial has already lapsed', function () {
    $workspace = Workspace::factory()->create();
    $price = PlanPrice::factory()->for(Plan::factory()->create())->create();

    Subscription::factory()->for($workspace)->create([
        'plan_id' => $price->plan_id,
        'plan_price_id' => $price->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->subDays(2),
    ]);

    $extended = $this->subscriptions->extendTrial($workspace, 5, $this->admin, 'Missed the warning emails');

    expect($extended->trial_ends_at->toDateString())->toBe(now()->addDays(5)->toDateString())
        ->and($extended->trial_ends_at->isFuture())->toBeTrue();
});

it('refuses to extend a workspace that is not on a trial', function () {
    $workspace = Workspace::factory()->create();
    $price = PlanPrice::factory()->for(Plan::factory()->create())->create();

    Subscription::factory()->for($workspace)->create([
        'plan_id' => $price->plan_id,
        'plan_price_id' => $price->id,
        'status' => SubscriptionStatus::Active,
    ]);

    expect(fn () => $this->subscriptions->extendTrial($workspace, 7, $this->admin, 'x'))
        ->toThrow(TrialNotExtendable::class);
});

it('refuses to extend a workspace with no subscription at all', function () {
    expect(fn () => $this->subscriptions->extendTrial(Workspace::factory()->create(), 7, $this->admin, 'x'))
        ->toThrow(TrialNotExtendable::class);
});

// ---------------------------------------------------------- override a limit

it('replaces the plan allowance with the overridden value', function () {
    $workspace = Workspace::factory()->create();
    $this->entitlements->rebuild($workspace);

    expect($this->entitlements->limitFor($workspace, 'seats'))->toBe(2);

    $this->entitlements->override($workspace, $this->seats, 25, $this->admin, 'Pilot with 25 people');

    expect($this->entitlements->limitFor($workspace, 'seats'))->toBe(25);
});

it('records a null override as unlimited', function () {
    $workspace = Workspace::factory()->create();

    $this->entitlements->override($workspace, $this->seats, null, $this->admin, 'Comped forever');

    expect($this->entitlements->limitFor($workspace, 'seats'))->toBeNull()
        ->and($this->entitlements->allows($workspace, 'seats', 10_000))->toBeTrue();
});

/*
 * The partial unique index on (workspace_id, feature_id) only tests
 * `revoked_at IS NULL`, so it treats an expired-but-unrevoked row as live. A
 * second override has to revoke the first outright or it hits that index.
 */
it('revokes the previous override when a new one replaces it', function () {
    $workspace = Workspace::factory()->create();

    $first = $this->entitlements->override($workspace, $this->seats, 10, $this->admin, 'First');
    $second = $this->entitlements->override($workspace, $this->seats, 20, $this->admin, 'Second');

    expect($first->fresh()->revoked_at)->not->toBeNull()
        ->and($second->revoked_at)->toBeNull()
        ->and($this->entitlements->limitFor($workspace, 'seats'))->toBe(20);
});

it('replaces an expired override that was never revoked', function () {
    $workspace = Workspace::factory()->create();

    $expired = WorkspaceEntitlementOverride::withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->id,
        'feature_id' => $this->seats->id,
        'value' => 99,
        'reason' => 'Expired trial bump',
        'granted_by_admin_id' => $this->admin->id,
        'expires_at' => now()->subDay(),
    ]);

    $this->entitlements->override($workspace, $this->seats, 30, $this->admin, 'New deal');

    expect($expired->fresh()->revoked_at)->not->toBeNull()
        ->and($this->entitlements->limitFor($workspace, 'seats'))->toBe(30);
});

it('puts the workspace back on its plan when the override is revoked', function () {
    $workspace = Workspace::factory()->create();

    $override = $this->entitlements->override($workspace, $this->seats, 25, $this->admin, 'Pilot');
    $this->entitlements->revokeOverride($override);

    $entitlement = $workspace->entitlements()->withoutWorkspaceScope()
        ->where('feature_key', 'seats')->first();

    expect($this->entitlements->limitFor($workspace, 'seats'))->toBe(2)
        ->and($entitlement->source)->toBe(EntitlementSource::Plan);
});

/*
 * Section 7's hard block is the reason sales can do this at all: a blocked
 * customer has to be able to write again the moment the limit is raised, not
 * after some later job notices.
 */
it('lifts an existing hard block the moment the limit is raised', function () {
    $workspace = Workspace::factory()->create();

    $this->entitlements->rebuild($workspace);
    $this->usage->setGauge($workspace, 'seats', 8);
    $this->usage->evaluate($workspace);

    expect($workspace->fresh()->isOverLimit())->toBeTrue();

    $this->entitlements->override($workspace, $this->seats, 25, $this->admin, 'Rescue');

    expect($workspace->fresh()->isOverLimit())->toBeFalse()
        ->and($workspace->fresh()->canWrite())->toBeTrue();
});

it('applies the hard block when an override lowers the limit below usage', function () {
    $workspace = Workspace::factory()->create();

    $this->entitlements->override($workspace, $this->seats, 50, $this->admin, 'Pilot');
    $this->usage->setGauge($workspace, 'seats', 8);
    $this->usage->evaluate($workspace);

    expect($workspace->fresh()->isOverLimit())->toBeFalse();

    $this->entitlements->override($workspace, $this->seats, 5, $this->admin, 'Pilot ended');

    expect($workspace->fresh()->isOverLimit())->toBeTrue()
        ->and($workspace->fresh()->over_limit_features)->toContain('seats');
});

it('re-applies the block when revoking an override drops them under the plan limit', function () {
    $workspace = Workspace::factory()->create();

    $override = $this->entitlements->override($workspace, $this->seats, 25, $this->admin, 'Pilot');
    $this->usage->setGauge($workspace, 'seats', 8);
    $this->usage->evaluate($workspace);

    expect($workspace->fresh()->isOverLimit())->toBeFalse();

    $this->entitlements->revokeOverride($override);

    // Back to the free plan's 2 seats with 8 in use.
    expect($workspace->fresh()->isOverLimit())->toBeTrue();
});
