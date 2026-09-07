<?php

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Support\Facades\Schema;

/**
 * The documented edge case in every shared-database tenancy setup is a table
 * that carries workspace_id but was never wired to the scope. It looks correct
 * and leaks. These tests make that impossible to add silently.
 */
$scoped = [
    App\Models\Subscription::class,
    App\Models\SubscriptionItem::class,
    App\Models\DunningState::class,
    App\Models\WorkspaceEntitlement::class,
    App\Models\WorkspaceEntitlementOverride::class,
    App\Models\UsageCounter::class,
    App\Models\UsageRecord::class,
    App\Models\InvoiceSummary::class,
];

// Deliberately NOT scoped, each for a stated reason.
$exempt = [
    // define tenancy: the switcher has to read them across workspaces
    'workspace_members' => App\Models\WorkspaceMember::class,
    'workspace_invitations' => App\Models\WorkspaceInvitation::class,
    // staff surfaces and system intake - nullable workspace_id, read centrally
    'audit_logs' => App\Models\AuditLog::class,
    'impersonation_sessions' => App\Models\ImpersonationSession::class,
    'webhook_events' => App\Models\WebhookEvent::class,
    'notification_logs' => App\Models\NotificationLog::class,
];

it('scopes tenant-owned models', function (string $model) {
    expect(class_uses_recursive($model))->toContain(BelongsToWorkspace::class);
})->with($scoped);

it('leaves tenancy-defining and central models unscoped', function (string $model) {
    expect(class_uses_recursive($model))->not->toContain(BelongsToWorkspace::class);
})->with(array_values($exempt));

// The one that actually protects us: a NEW table with workspace_id that nobody
// classified fails here rather than leaking in production.
it('classifies every table that carries a workspace id', function () use ($scoped, $exempt) {
    $classified = collect($scoped)
        ->map(fn (string $model) => (new $model)->getTable())
        ->merge(array_keys($exempt))
        ->push('workspaces')
        ->all();

    $withWorkspaceId = collect(Schema::getTables())
        ->pluck('name')
        ->filter(fn (string $table) => Schema::hasColumn($table, 'workspace_id'))
        ->values()
        ->all();

    expect(array_diff($withWorkspaceId, $classified))->toBe(
        [],
        'A table carries workspace_id but is neither scoped nor explicitly exempt.'
    );
});
