<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
    $this->ownerMembership = $this->workspace->owners()->first();
});

it('changes a member role', function () {
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    $this->actingAs($this->owner)
        ->put(route('workspace.member.update', $member), ['role' => 'admin'])
        ->assertRedirect();

    expect($member->fresh()->role)->toBe(WorkspaceRole::Admin);
});

// Section 3: only an owner may create another owner.
it('stops an admin promoting someone to owner', function () {
    $admin = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    $this->actingAs($admin)
        ->put(route('workspace.member.update', $member), ['role' => 'owner'])
        ->assertForbidden();

    expect($member->fresh()->role)->not->toBe(WorkspaceRole::Owner);
});

it('refuses to demote the last owner', function () {
    $this->actingAs($this->owner)
        ->put(route('workspace.member.update', $this->ownerMembership), ['role' => 'admin'])
        ->assertForbidden();

    expect($this->ownerMembership->fresh()->role)->toBe(WorkspaceRole::Owner);
});

it('removes a member and frees the seat', function () {
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    $this->actingAs($this->owner)
        ->delete(route('workspace.member.destroy', $member))
        ->assertRedirect();

    expect(WorkspaceMember::find($member->id))->toBeNull()
        ->and($this->workspace->fresh()->seatsUsed())->toBe(1);
});

it('refuses to remove the last owner', function () {
    $this->actingAs($this->owner)
        ->delete(route('workspace.member.destroy', $this->ownerMembership))
        ->assertForbidden();
});

it('never lets a stranger touch a membership', function () {
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('workspace.member.destroy', $member))
        ->assertForbidden();
});
