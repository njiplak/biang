<?php

use App\Contract\Workspace\InvitationContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

it('invites someone by email with a chosen role', function () {
    $this->actingAs($this->owner)
        ->post(route('workspace.invitation.store', $this->workspace), [
            'email' => 'new@example.com',
            'role' => 'admin',
        ])
        ->assertRedirect();

    $invitation = WorkspaceInvitation::firstWhere('email', 'new@example.com');

    expect($invitation->role)->toBe(WorkspaceRole::Admin)
        ->and($invitation->isPending())->toBeTrue();
});

it('rejects an invalid role', function () {
    $this->actingAs($this->owner)
        ->post(route('workspace.invitation.store', $this->workspace), [
            'email' => 'new@example.com',
            'role' => 'superuser',
        ])
        ->assertSessionHasErrors('role');
});

it('stops a member inviting anyone', function () {
    $member = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($member)->create();

    $this->actingAs($member)
        ->post(route('workspace.invitation.store', $this->workspace), [
            'email' => 'new@example.com',
            'role' => 'member',
        ])
        ->assertForbidden();
});

// Section 7: the seat limit surfaces as a message naming the fix, not a 500.
it('shows the seat limit message rather than failing hard', function () {
    // free plan seeds 2 seats; owner holds one
    $this->actingAs($this->owner)
        ->post(route('workspace.invitation.store', $this->workspace), [
            'email' => 'a@example.com', 'role' => 'member',
        ])->assertRedirect();

    $this->actingAs($this->owner)
        ->post(route('workspace.invitation.store', $this->workspace), [
            'email' => 'b@example.com', 'role' => 'member',
        ])
        ->assertSessionHasErrors('errors');

    expect(WorkspaceInvitation::where('email', 'b@example.com')->exists())->toBeFalse();
});

it('revokes an invitation and frees the seat', function () {
    $invitation = app(InvitationContract::class)
        ->invite($this->workspace, 'a@example.com', WorkspaceRole::Member, $this->owner);

    $this->actingAs($this->owner)
        ->delete(route('workspace.invitation.destroy', $invitation))
        ->assertRedirect();

    expect($invitation->fresh()->revoked_at)->not->toBeNull()
        ->and($this->workspace->fresh()->seatsUsed())->toBe(1);
});

it('resends an invitation and rotates its token', function () {
    $invitation = app(InvitationContract::class)
        ->invite($this->workspace, 'a@example.com', WorkspaceRole::Member, $this->owner);
    $originalHash = $invitation->token_hash;

    $this->actingAs($this->owner)
        ->post(route('workspace.invitation.resend', $invitation))
        ->assertRedirect();

    expect($invitation->fresh()->token_hash)->not->toBe($originalHash)
        ->and($invitation->fresh()->send_count)->toBe(2);
});

// Section 5 Path C: accepting joins an EXISTING workspace.
it('accepts an invitation and creates the membership', function () {
    $invitation = app(InvitationContract::class)
        ->invite($this->workspace, 'new@example.com', WorkspaceRole::Admin, $this->owner);
    $joiner = User::factory()->create(['email' => 'new@example.com']);

    $this->actingAs($joiner)
        ->post(route('invitation.accept', $invitation->plainToken))
        ->assertRedirect();

    expect($joiner->fresh()->roleIn($this->workspace))->toBe(WorkspaceRole::Admin)
        ->and($joiner->fresh()->current_workspace_id)->toBe($this->workspace->id)
        ->and($joiner->fresh()->hasConsumedTrial())->toBeFalse();
});

it('rejects a bad invitation token with a message', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('invitation.accept', 'not-a-real-token'))
        ->assertSessionHasErrors('errors');
});

it('requires a login before accepting', function () {
    $invitation = app(InvitationContract::class)
        ->invite($this->workspace, 'new@example.com', WorkspaceRole::Member, $this->owner);

    $this->post(route('invitation.accept', $invitation->plainToken))
        ->assertRedirect('/auth/login');
});
