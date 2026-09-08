<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

function memberOf(Workspace $workspace, WorkspaceRole $role): User
{
    $user = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->create(['role' => $role]);

    return $user;
}

// Section 2: authorisation is answered per workspace, never by who you are.
it('grants a stranger nothing at all', function () {
    $ws = Workspace::factory()->create();
    $stranger = User::factory()->create();

    expect($stranger->can('view', $ws))->toBeFalse()
        ->and($stranger->can('update', $ws))->toBeFalse()
        ->and($stranger->can('manageBilling', $ws))->toBeFalse()
        ->and($stranger->can('inviteMembers', $ws))->toBeFalse()
        ->and($stranger->can('write', $ws))->toBeFalse();
});

it('grants an owner everything', function () {
    // Paying, because writing is now something a live plan buys - an expired
    // workspace refuses its owner the same as anybody else.
    $ws = Workspace::factory()->paying()->create();
    $owner = memberOf($ws, WorkspaceRole::Owner);

    expect($owner->can('view', $ws))->toBeTrue()
        ->and($owner->can('update', $ws))->toBeTrue()
        ->and($owner->can('delete', $ws))->toBeTrue()
        ->and($owner->can('transferOwnership', $ws))->toBeTrue()
        ->and($owner->can('manageBilling', $ws))->toBeTrue()
        ->and($owner->can('inviteMembers', $ws))->toBeTrue()
        ->and($owner->can('write', $ws))->toBeTrue();
});

// Section 3: admins manage people and settings, and explicitly cannot see or
// touch billing.
it('keeps an admin out of billing', function () {
    $ws = Workspace::factory()->paying()->create();
    $admin = memberOf($ws, WorkspaceRole::Admin);

    expect($admin->can('inviteMembers', $ws))->toBeTrue()
        ->and($admin->can('update', $ws))->toBeTrue()
        ->and($admin->can('manageBilling', $ws))->toBeFalse()
        ->and($admin->can('delete', $ws))->toBeFalse()
        ->and($admin->can('transferOwnership', $ws))->toBeFalse();
});

// Section 3: billing managers do billing only, and cannot manage people.
it('keeps a billing manager out of people management', function () {
    $ws = Workspace::factory()->create();
    $billing = memberOf($ws, WorkspaceRole::BillingManager);

    expect($billing->can('manageBilling', $ws))->toBeTrue()
        ->and($billing->can('inviteMembers', $ws))->toBeFalse()
        ->and($billing->can('update', $ws))->toBeFalse();
});

it('makes a viewer read only', function () {
    $ws = Workspace::factory()->create();
    $viewer = memberOf($ws, WorkspaceRole::Viewer);

    expect($viewer->can('view', $ws))->toBeTrue()
        ->and($viewer->can('write', $ws))->toBeFalse()
        ->and($viewer->can('inviteMembers', $ws))->toBeFalse();
});

// Section 7's hard block has to reach authorisation, not just the UI - this is
// where the workspace state machine meets the policy layer.
it('blocks writing when the workspace is over its limit, even for the owner', function () {
    $ws = Workspace::factory()->overLimit()->create();
    $owner = memberOf($ws, WorkspaceRole::Owner);

    expect($owner->can('write', $ws))->toBeFalse()
        ->and($owner->can('view', $ws))->toBeTrue();
});

it('blocks writing when the workspace is suspended but still allows reading', function () {
    $ws = Workspace::factory()->suspended()->create();
    $owner = memberOf($ws, WorkspaceRole::Owner);

    expect($owner->can('write', $ws))->toBeFalse()
        ->and($owner->can('view', $ws))->toBeTrue();
});

// Section 9: past due deliberately keeps full access - locking people out on
// day one of a failed payment turns a card problem into a cancellation.
it('keeps writing available while past due', function () {
    $ws = Workspace::factory()->create(['billing_status' => App\Enums\BillingStatus::PastDue]);
    $member = memberOf($ws, WorkspaceRole::Member);

    expect($member->can('write', $ws))->toBeTrue();
});

// Billing is still reachable while over limit, or the customer cannot buy the
// upgrade that fixes it.
it('still allows billing management while over limit', function () {
    $ws = Workspace::factory()->overLimit()->create();
    $owner = memberOf($ws, WorkspaceRole::Owner);

    expect($owner->can('manageBilling', $ws))->toBeTrue();
});
