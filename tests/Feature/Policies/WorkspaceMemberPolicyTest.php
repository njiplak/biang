<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

function membership(Workspace $workspace, WorkspaceRole $role): WorkspaceMember
{
    return WorkspaceMember::factory()->for($workspace)->for(User::factory())->create(['role' => $role]);
}

it('lets owners and admins remove an ordinary member', function () {
    $ws = Workspace::factory()->create();
    $owner = membership($ws, WorkspaceRole::Owner)->user;
    $admin = membership($ws, WorkspaceRole::Admin)->user;
    $target = membership($ws, WorkspaceRole::Member);

    expect($owner->can('delete', $target))->toBeTrue()
        ->and($admin->can('delete', $target))->toBeTrue();
});

it('stops members and viewers removing anyone else', function () {
    $ws = Workspace::factory()->create();
    membership($ws, WorkspaceRole::Owner);
    $member = membership($ws, WorkspaceRole::Member)->user;
    $viewer = membership($ws, WorkspaceRole::Viewer)->user;
    $target = membership($ws, WorkspaceRole::Member);

    expect($member->can('delete', $target))->toBeFalse()
        ->and($viewer->can('delete', $target))->toBeFalse();
});

// Section 3: a workspace must always have at least one owner, and the last
// owner cannot leave or be removed - they have to hand ownership over first.
it('refuses to remove the last owner', function () {
    $ws = Workspace::factory()->create();
    $onlyOwner = membership($ws, WorkspaceRole::Owner);
    membership($ws, WorkspaceRole::Admin);

    expect($onlyOwner->user->can('delete', $onlyOwner))->toBeFalse();
});

it('refuses to demote the last owner', function () {
    $ws = Workspace::factory()->create();
    $onlyOwner = membership($ws, WorkspaceRole::Owner);

    expect($onlyOwner->user->can('update', $onlyOwner))->toBeFalse();
});

it('allows removing an owner once another owner exists', function () {
    $ws = Workspace::factory()->create();
    $first = membership($ws, WorkspaceRole::Owner);
    $second = membership($ws, WorkspaceRole::Owner);

    expect($second->user->can('delete', $first))->toBeTrue()
        ->and($first->user->can('delete', $first))->toBeTrue();
});

it('lets an ordinary member leave on their own', function () {
    $ws = Workspace::factory()->create();
    membership($ws, WorkspaceRole::Owner);
    $member = membership($ws, WorkspaceRole::Member);

    expect($member->user->can('delete', $member))->toBeTrue();
});

it('stops an admin promoting anyone to owner', function () {
    // paying(), because a role change is a write and an unpaid workspace is
    // read-only - this test is about who may promote, not about being expired.
    $ws = Workspace::factory()->paying()->create();
    membership($ws, WorkspaceRole::Owner);
    $admin = membership($ws, WorkspaceRole::Admin)->user;
    $target = membership($ws, WorkspaceRole::Member);

    expect($admin->can('update', $target))->toBeTrue()
        ->and($admin->can('assignRole', [$target, WorkspaceRole::Owner]))->toBeFalse()
        ->and($admin->can('assignRole', [$target, WorkspaceRole::Admin]))->toBeTrue();
});

it('lets an owner promote someone to owner', function () {
    $ws = Workspace::factory()->paying()->create();
    $owner = membership($ws, WorkspaceRole::Owner)->user;
    $target = membership($ws, WorkspaceRole::Member);

    expect($owner->can('assignRole', [$target, WorkspaceRole::Owner]))->toBeTrue();
});

it('never lets a stranger touch a membership', function () {
    $ws = Workspace::factory()->create();
    $target = membership($ws, WorkspaceRole::Member);
    $stranger = User::factory()->create();

    expect($stranger->can('delete', $target))->toBeFalse()
        ->and($stranger->can('update', $target))->toBeFalse();
});

/*
 * Section 6: read-only means no writing, and a role change is a write. The
 * escape hatches stay open either side of it - removing somebody frees a seat
 * and is how a workspace gets back under its limit (section 7), and the last
 * owner still has to be able to hand over and leave.
 */
it('blocks role changes on a read-only workspace but not removals', function () {
    $ws = Workspace::factory()->create();
    $owner = membership($ws, WorkspaceRole::Owner)->user;
    membership($ws, WorkspaceRole::Owner);
    $target = membership($ws, WorkspaceRole::Member);

    expect($ws->canWrite())->toBeFalse()
        ->and($owner->can('update', $target))->toBeFalse()
        ->and($owner->can('assignRole', [$target, WorkspaceRole::Admin]))->toBeFalse()
        ->and($owner->can('delete', $target))->toBeTrue();
});

it('allows role changes again once there is a live plan', function () {
    $ws = Workspace::factory()->paying()->create();
    $owner = membership($ws, WorkspaceRole::Owner)->user;
    $target = membership($ws, WorkspaceRole::Member);

    expect($owner->can('update', $target))->toBeTrue();
});
