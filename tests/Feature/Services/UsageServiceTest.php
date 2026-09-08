<?php

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\UsageContract;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\UsageRecord;
use App\Models\Workspace;

beforeEach(function () {
    $this->usage = app(UsageContract::class);
    $this->entitlements = app(EntitlementContract::class);

    $this->seats = Feature::factory()->create(['key' => 'seats']);
    $this->calls = Feature::factory()->metered()->create(['key' => 'api_calls']);

    $free = Plan::factory()->floor()->create();
    $free->features()->attach($this->seats, ['value' => 3]);
    $free->features()->attach($this->calls, ['value' => 100]);

    /*
     * Paying, because there is no free tier: an unpaid workspace is read-only
     * whatever its limits say, and these tests are about the limit machinery,
     * not about being expired.
     */
    $this->workspace = Workspace::factory()->paying()->create();
    $this->entitlements->rebuild($this->workspace);
});

it('sets and reads a gauge', function () {
    $this->usage->setGauge($this->workspace, 'seats', 2);

    expect($this->usage->current($this->workspace, 'seats'))->toBe(2);
});

it('increments a gauge without creating a second row', function () {
    $this->usage->setGauge($this->workspace, 'seats', 1);
    $this->usage->increment($this->workspace, 'seats', 2);

    expect($this->usage->current($this->workspace, 'seats'))->toBe(3)
        ->and($this->workspace->usageCounters()->where('feature_key', 'seats')->count())->toBe(1);
});

it('decrements a gauge and never goes negative', function () {
    $this->usage->setGauge($this->workspace, 'seats', 1);
    $this->usage->increment($this->workspace, 'seats', -5);

    expect($this->usage->current($this->workspace, 'seats'))->toBe(0);
});

it('reports zero for a feature never used', function () {
    expect($this->usage->current($this->workspace, 'seats'))->toBe(0);
});

// Section 7: the workspace goes read-only when it exceeds a limit, and it has
// to say exactly which limit so the upgrade prompt can name it.
it('marks the workspace over limit and records which feature', function () {
    $this->usage->setGauge($this->workspace, 'seats', 4);

    $this->usage->evaluate($this->workspace);

    $fresh = $this->workspace->fresh();
    expect($fresh->isOverLimit())->toBeTrue()
        ->and($fresh->over_limit_features)->toBe(['seats'])
        ->and($fresh->canWrite())->toBeFalse();
});

it('leaves a workspace inside its limits alone', function () {
    $this->usage->setGauge($this->workspace, 'seats', 3);

    $this->usage->evaluate($this->workspace);

    expect($this->workspace->fresh()->isOverLimit())->toBeFalse();
});

// Section 7: "Upgrading or buying the add-on restores writing immediately."
it('clears the over limit flag once usage comes back under', function () {
    $this->usage->setGauge($this->workspace, 'seats', 9);
    $this->usage->evaluate($this->workspace);
    expect($this->workspace->fresh()->isOverLimit())->toBeTrue();

    $this->usage->setGauge($this->workspace, 'seats', 1);
    $this->usage->evaluate($this->workspace);

    $fresh = $this->workspace->fresh();
    expect($fresh->isOverLimit())->toBeFalse()
        ->and($fresh->over_limit_features)->toBeNull()
        ->and($fresh->canWrite())->toBeTrue();
});

it('lists every breached feature, not just the first', function () {
    $this->usage->setGauge($this->workspace, 'seats', 10);
    $this->usage->setGauge($this->workspace, 'api_calls', 500);

    $this->usage->evaluate($this->workspace);

    expect($this->workspace->fresh()->over_limit_features)
        ->toEqualCanonicalizing(['seats', 'api_calls']);
});

it('never marks an unlimited feature as over limit', function () {
    $plan = Plan::factory()->create();
    $plan->features()->attach($this->seats, ['value' => null]);
    App\Models\Subscription::factory()->for($this->workspace)->for($plan)->create();
    $this->entitlements->rebuild($this->workspace);

    $this->usage->setGauge($this->workspace, 'seats', 100000);
    $this->usage->evaluate($this->workspace);

    expect($this->workspace->fresh()->isOverLimit())->toBeFalse();
});

// Section 8: Dodo owns chargebacks, so a disputed metered bill needs the
// individual events. Double-reporting must be a no-op, not an overcharge.
it('records metered usage as ledger events', function () {
    $this->usage->record($this->workspace, 'api_calls', 25, 'evt-1');
    $this->usage->record($this->workspace, 'api_calls', 30, 'evt-2');

    expect(UsageRecord::withoutWorkspaceScope()->where('workspace_id', $this->workspace->id)->count())->toBe(2)
        ->and($this->usage->current($this->workspace, 'api_calls'))->toBe(55);
});

it('ignores a replayed idempotency key without double counting', function () {
    $this->usage->record($this->workspace, 'api_calls', 25, 'evt-1');
    $this->usage->record($this->workspace, 'api_calls', 25, 'evt-1');

    expect(UsageRecord::withoutWorkspaceScope()->where('workspace_id', $this->workspace->id)->count())->toBe(1)
        ->and($this->usage->current($this->workspace, 'api_calls'))->toBe(25);
});
