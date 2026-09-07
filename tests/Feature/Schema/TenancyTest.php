<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use Illuminate\Database\QueryException;

it('lets one person belong to many workspaces with a different role in each', function () {
    $user = User::factory()->create();
    $paid = Workspace::factory()->create();
    $free = Workspace::factory()->create();

    WorkspaceMember::factory()->for($paid)->for($user)->owner()->create();
    WorkspaceMember::factory()->for($free)->for($user)->viewer()->create();

    expect($user->workspaces)->toHaveCount(2)
        ->and($user->roleIn($paid))->toBe(WorkspaceRole::Owner)
        ->and($user->roleIn($free))->toBe(WorkspaceRole::Viewer);
});

// Section 2: what someone may do is decided by which workspace they are looking
// at, never by who they are.
it('answers permission per workspace, not per person', function () {
    $user = User::factory()->create();
    $paid = Workspace::factory()->create();
    $free = Workspace::factory()->create();

    WorkspaceMember::factory()->for($paid)->for($user)->owner()->create();
    WorkspaceMember::factory()->for($free)->for($user)->viewer()->create();

    expect($user->roleIn($paid)->canManageBilling())->toBeTrue()
        ->and($user->roleIn($free)->canManageBilling())->toBeFalse();
});

it('refuses to add the same person to a workspace twice', function () {
    $user = User::factory()->create();
    $ws = Workspace::factory()->create();

    WorkspaceMember::factory()->for($ws)->for($user)->create();

    expect(fn () => WorkspaceMember::factory()->for($ws)->for($user)->create())
        ->toThrow(QueryException::class);
});

it('finds the owners of a workspace', function () {
    $ws = Workspace::factory()->create();
    WorkspaceMember::factory()->for($ws)->owner()->create();
    WorkspaceMember::factory()->for($ws)->count(3)->create(['role' => WorkspaceRole::Member]);

    expect($ws->owners()->count())->toBe(1);
});

// Section 7: a pending invite must reserve a seat, or ten pending invites all
// pass a five seat check and the workspace blows past its limit on acceptance.
it('counts pending invitations against seat usage', function () {
    $ws = Workspace::factory()->create();
    WorkspaceMember::factory()->for($ws)->count(3)->create();
    WorkspaceInvitation::factory()->for($ws)->count(2)->create();

    expect($ws->seatsUsed())->toBe(5);
});

it('stops counting invitations once they are accepted, revoked or expired', function () {
    $ws = Workspace::factory()->create();
    WorkspaceMember::factory()->for($ws)->create();
    WorkspaceInvitation::factory()->for($ws)->create();
    WorkspaceInvitation::factory()->for($ws)->accepted()->create();
    WorkspaceInvitation::factory()->for($ws)->revoked()->create();
    WorkspaceInvitation::factory()->for($ws)->expired()->create();

    expect($ws->seatsUsed())->toBe(2);
});

it('refuses a second live invitation for the same email regardless of case', function () {
    $ws = Workspace::factory()->create();
    WorkspaceInvitation::factory()->for($ws)->create(['email' => 'Bob@example.com']);

    expect(fn () => WorkspaceInvitation::factory()->for($ws)->create(['email' => 'bob@example.com']))
        ->toThrow(QueryException::class);
});

it('allows re-inviting an email whose earlier invitation was revoked', function () {
    $ws = Workspace::factory()->create();
    WorkspaceInvitation::factory()->for($ws)->revoked()->create(['email' => 'bob@example.com']);

    $fresh = WorkspaceInvitation::factory()->for($ws)->create(['email' => 'bob@example.com']);

    expect($fresh->isPending())->toBeTrue();
});

// Section 12: one trial per person ever, consumed by STARTING one - so a Path C
// invitee who joins someone else's trialing workspace keeps their own.
it('tracks trial consumption on the person', function () {
    $user = User::factory()->create();
    expect($user->hasConsumedTrial())->toBeFalse();

    $user->update(['trial_consumed_at' => now()]);

    expect($user->fresh()->hasConsumedTrial())->toBeTrue();
});
