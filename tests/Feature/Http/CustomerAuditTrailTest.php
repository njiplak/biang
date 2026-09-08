<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 10 frames the audit trail as a staff need - "answer a support ticket
 * in under a minute" - and until now only staff actions were recorded. The
 * questions support actually gets are about customers: who removed this person,
 * who closed this workspace, who handed ownership over.
 *
 * Deliberately narrow. This is the handful of consequential, security-relevant
 * things a customer can do today, not an attempt at a full activity log - that
 * waits until there is a product to log.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

it('records a role change with what it changed from and to', function () {
    subscribeWorkspace($this->workspace);
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    $this->actingAs($this->owner)
        ->put(route('workspace.member.update', $member), ['role' => 'admin'])
        ->assertRedirect();

    $log = AuditLog::where('action', 'workspace.member_role_changed')->sole();

    expect($log->workspace_id)->toBe($this->workspace->id)
        ->and($log->actor_id)->toBe($this->owner->id)
        ->and($log->actor_type)->toBe(User::class)
        ->and($log->subject_id)->toBe($member->id)
        ->and($log->changes)->toBe(['from' => 'member', 'to' => 'admin']);
});

it('records a member removal', function () {
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    $this->actingAs($this->owner)
        ->delete(route('workspace.member.destroy', $member))
        ->assertRedirect();

    $log = AuditLog::where('action', 'workspace.member_removed')->sole();

    expect($log->workspace_id)->toBe($this->workspace->id)
        ->and($log->actor_id)->toBe($this->owner->id)
        ->and($log->subject_id)->toBe($member->id);
});

it('records an ownership transfer', function () {
    $successor = WorkspaceMember::factory()->for($this->workspace)->create();

    $this->actingAs($this->owner)
        ->post(route('workspace.transfer', $this->workspace), ['member_id' => $successor->id])
        ->assertRedirect();

    $log = AuditLog::where('action', 'workspace.ownership_transferred')->sole();

    expect($log->workspace_id)->toBe($this->workspace->id)
        ->and($log->actor_id)->toBe($this->owner->id)
        ->and($log->subject_id)->toBe($successor->id);
});

it('records a workspace closing', function () {
    $this->actingAs($this->owner)
        ->delete(route('workspace.destroy', $this->workspace))
        ->assertRedirect(route('dashboard'));

    $log = AuditLog::where('action', 'workspace.closed')->sole();

    expect($log->workspace_id)->toBe($this->workspace->id)
        ->and($log->actor_id)->toBe($this->owner->id);
});

// Nothing is recorded for an action the policies refused.
it('records nothing when the action was forbidden', function () {
    $stranger = User::factory()->create();
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    $this->actingAs($stranger)
        ->delete(route('workspace.member.destroy', $member))
        ->assertForbidden();

    expect(AuditLog::count())->toBe(0);
});

/*
 * The distinction the audit_logs table carries impersonation_session_id for:
 * "the customer did this" and "we did this as them" must not read the same.
 */
it('marks an action taken through impersonation', function () {
    $staff = AdminUser::factory()->create();
    $member = WorkspaceMember::factory()->for($this->workspace)->create();

    $session = ImpersonationSession::create([
        'ulid' => (string) Illuminate\Support\Str::ulid(),
        'admin_user_id' => $staff->id,
        'user_id' => $this->owner->id,
        'workspace_id' => $this->workspace->id,
        'reason' => 'support ticket 123',
        'started_at' => now(),
    ]);

    $this->actingAs($this->owner)
        ->withSession([ImpersonationController::SESSION_KEY => $session->id])
        ->delete(route('workspace.member.destroy', $member))
        ->assertRedirect();

    expect(AuditLog::where('action', 'workspace.member_removed')->sole()->impersonation_session_id)
        ->toBe($session->id);
});
