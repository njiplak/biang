<?php

use App\Enums\AccessStatus;
use App\Enums\BillingStatus;
use App\Enums\WorkspaceDisplayState;
use App\Models\Workspace;

// Spec section 6 presents ONE state to support, but the underlying data is two
// independent axes. These tests pin the collapse, especially the combinations
// section 6 cannot express.

/*
 * There is no free tier. A workspace with no live subscription - one that never
 * bought, and one whose subscription ended - keeps everything it has and keeps
 * it readable, and that is all it may do.
 */
it('reports expired when there is no subscription', function () {
    $ws = Workspace::factory()->create();

    expect($ws->displayState())->toBe(WorkspaceDisplayState::Expired)
        ->and($ws->canWrite())->toBeFalse()
        ->and($ws->canRead())->toBeTrue()
        ->and($ws->canExport())->toBeTrue();
});

it('reports expired once a subscription is cancelled', function () {
    $ws = Workspace::factory()->create(['billing_status' => BillingStatus::Canceled]);

    expect($ws->displayState())->toBe(WorkspaceDisplayState::Expired)
        ->and($ws->canWrite())->toBeFalse();
});

/*
 * Expired beats over limit, and that order is the point. An expired workspace
 * cannot write at all, so naming a seat limit would point the customer at a
 * problem that is not theirs to fix and hide the one that is - nobody is
 * paying for this workspace.
 */
it('prefers expired to over limit', function () {
    $ws = Workspace::factory()->overLimit()->create([
        'billing_status' => BillingStatus::Unpaid,
    ]);

    expect($ws->displayState())->toBe(WorkspaceDisplayState::Expired)
        ->and($ws->canWrite())->toBeFalse();
});

it('reports the billing status when access is unremarkable', function () {
    $ws = Workspace::factory()->create(['billing_status' => BillingStatus::Active]);
    expect($ws->displayState())->toBe(WorkspaceDisplayState::Active);

    $ws = Workspace::factory()->create(['billing_status' => BillingStatus::Trialing]);
    expect($ws->displayState())->toBe(WorkspaceDisplayState::Trialing);

    $ws = Workspace::factory()->create(['billing_status' => BillingStatus::PastDue]);
    expect($ws->displayState())->toBe(WorkspaceDisplayState::PastDue);
});

// The combination section 6 cannot represent: billing says "Unchanged" for
// Over limit, which means a workspace can be Active AND Over limit at once.
it('reports over limit even while the subscription is active', function () {
    $ws = Workspace::factory()->overLimit()->create([
        'billing_status' => BillingStatus::Active,
    ]);

    expect($ws->displayState())->toBe(WorkspaceDisplayState::OverLimit)
        ->and($ws->canWrite())->toBeFalse()
        ->and($ws->canRead())->toBeTrue();
});

// Section 7's hard block is stricter than section 9's "past due keeps access",
// so over limit has to win or an unpaid workspace keeps writing.
it('prefers over limit to past due', function () {
    $ws = Workspace::factory()->overLimit()->create([
        'billing_status' => BillingStatus::PastDue,
    ]);

    expect($ws->displayState())->toBe(WorkspaceDisplayState::OverLimit)
        ->and($ws->canWrite())->toBeFalse();
});

it('keeps full write access while past due', function () {
    $ws = Workspace::factory()->create(['billing_status' => BillingStatus::PastDue]);

    expect($ws->canWrite())->toBeTrue()
        ->and($ws->canRead())->toBeTrue();
});

it('prefers suspended to every billing status', function () {
    $ws = Workspace::factory()->suspended()->create([
        'billing_status' => BillingStatus::Active,
    ]);

    expect($ws->displayState())->toBe(WorkspaceDisplayState::Suspended)
        ->and($ws->canWrite())->toBeFalse()
        ->and($ws->canRead())->toBeTrue()
        ->and($ws->canExport())->toBeTrue();
});

it('prefers deleted to everything', function () {
    $ws = Workspace::factory()->suspended()->overLimit()->create([
        'billing_status' => BillingStatus::PastDue,
        'access_status' => AccessStatus::Deleted,
    ]);

    expect($ws->displayState())->toBe(WorkspaceDisplayState::Deleted)
        ->and($ws->canRead())->toBeFalse()
        ->and($ws->canLogIn())->toBeFalse();
});

it('casts both state axes to enums', function () {
    $ws = Workspace::factory()->create();

    expect($ws->billing_status)->toBeInstanceOf(BillingStatus::class)
        ->and($ws->access_status)->toBeInstanceOf(AccessStatus::class);
});
