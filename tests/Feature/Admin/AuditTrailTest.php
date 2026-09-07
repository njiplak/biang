<?php

use App\Contract\Admin\AuditViewContract;
use App\Contract\Billing\EntitlementContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 14 phase 6's audit trail. Suspends, grants and overrides used to
 * record who-and-why on their own rows only, which answered "what is this
 * customer's state" but never "what did we do to them, and when".
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->admin = AdminUser::factory()->create(['name' => 'Root']);
    $this->admin->assignRole('super-admin');

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    $this->as = fn () => $this->actingAs($this->admin, 'admin');
});

// -------------------------------------------------------- every staff action

it('records a suspension against the customer', function () {
    ($this->as)()->post(route('admin.customer.suspend', $this->workspace), ['reason' => 'Spam reports']);

    $log = AuditLog::where('action', 'workspace.suspended')->firstOrFail();

    expect($log->workspace_id)->toBe($this->workspace->id)
        ->and($log->actor_type)->toBe(AdminUser::class)
        ->and($log->actor_id)->toBe($this->admin->id)
        ->and($log->changes['reason'])->toBe('Spam reports')
        ->and($log->ip_address)->not->toBeNull();
});

it('records a comped plan and what it was for', function () {
    ($this->as)()->post(route('admin.customer.plan', $this->workspace), [
        'plan_price_id' => $this->price->id,
        'reason' => 'Closed over a call',
    ]);

    $log = AuditLog::where('action', 'plan.granted')->firstOrFail();

    expect($log->changes['reason'])->toBe('Closed over a call')
        ->and($log->changes['plan_price_id'])->toBe($this->price->id);
});

// Granting and changing land in different states, so they are different events.
it('tells a plan change apart from a first grant', function () {
    ($this->as)()->post(route('admin.customer.plan', $this->workspace), [
        'plan_price_id' => $this->price->id, 'reason' => 'First',
    ]);

    $starter = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))
        ->where('billing_interval', 'month')->firstOrFail();

    ($this->as)()->post(route('admin.customer.plan', $this->workspace), [
        'plan_price_id' => $starter->id, 'reason' => 'Downsized',
    ]);

    expect(AuditLog::where('action', 'plan.granted')->count())->toBe(1)
        ->and(AuditLog::where('action', 'plan.changed')->count())->toBe(1);
});

it('records an overridden limit with the value that was given', function () {
    $seats = Feature::where('key', 'seats')->firstOrFail();

    ($this->as)()->post(route('admin.customer.override.store', $this->workspace), [
        'feature_id' => $seats->id,
        'value' => null,
        'reason' => 'Comped forever',
    ]);

    $log = AuditLog::where('action', 'entitlement.overridden')->firstOrFail();

    expect($log->changes['feature'])->toBe('seats')
        // Null is unlimited, and "gave them everything" has to read
        // differently to "gave them 50".
        ->and($log->changes['value'])->toBeNull()
        ->and($log->workspace_id)->toBe($this->workspace->id);
});

it('records revoking an override', function () {
    $seats = Feature::where('key', 'seats')->firstOrFail();
    $override = app(EntitlementContract::class)
        ->override($this->workspace, $seats, 50, $this->admin, 'Pilot');

    ($this->as)()->delete(route('admin.customer.override.destroy', [$this->workspace, $override->id]));

    expect(AuditLog::where('action', 'entitlement.override_revoked')->exists())->toBeTrue();
});

it('records a staff account being created and offboarded', function () {
    ($this->as)()->post(route('admin.staff.store'), [
        'name' => 'New Hire', 'email' => 'hire@example.com',
        'password' => 'Str0ng-password!', 'role' => 'support',
    ]);

    $hire = AdminUser::where('email', 'hire@example.com')->firstOrFail();

    ($this->as)()->delete(route('admin.staff.destroy', $hire));

    expect(AuditLog::where('action', 'staff.created')->firstOrFail()->changes['role'])->toBe('support')
        ->and(AuditLog::where('action', 'staff.offboarded')->exists())->toBeTrue();
});

/*
 * The catalogue change that moves existing customers. What the limits were
 * before is the part that cannot be reconstructed afterwards.
 */
it('records what a plan limit was before it changed', function () {
    $free = Plan::where('is_free', true)->firstOrFail();
    $seats = $free->features()->where('key', 'seats')->firstOrFail();

    ($this->as)()->put(route('admin.catalog.plan.features', $free), [
        'limits' => [['feature_id' => $seats->id, 'value' => 9]],
    ]);

    $log = AuditLog::where('action', 'catalog.limits_changed')->firstOrFail();

    expect($log->changes['from']['seats'])->toBe(2)
        ->and($log->changes['to']['seats'])->toBe(9);
});

it('records publishing an announcement', function () {
    ($this->as)()->post(route('admin.announcement.store'), [
        'title' => 'Maintenance', 'body' => 'Sunday.',
        'audience' => 'all', 'severity' => 'info',
    ]);

    $announcement = \App\Models\Announcement::firstOrFail();
    ($this->as)()->post(route('admin.announcement.publish', $announcement));

    expect(AuditLog::where('action', 'announcement.created')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'announcement.published')->exists())->toBeTrue();
});

// A refused action must leave no trace of having happened.
it('records nothing when the action was rejected', function () {
    ($this->as)()->post(route('admin.customer.suspend', $this->workspace), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(AuditLog::where('action', 'workspace.suspended')->count())->toBe(0);
});

/*
 * A role change alters what every staff member holding it may do. It is the
 * most privileged action in the console and goes through the generic
 * backoffice CRUD, which is exactly where it would be missed.
 */
it('records a staff role being changed', function () {
    $role = \Spatie\Permission\Models\Role::where('name', 'support')
        ->where('guard_name', 'admin')->firstOrFail();

    ($this->as)()->put(route('admin.setting.role.update', $role->id), [
        'name' => 'support',
        'guard_name' => 'admin',
    ]);

    expect(AuditLog::where('action', 'role.updated')->exists())->toBeTrue();
});

/*
 * That service layer RETURNS its failures rather than throwing, so an
 * unguarded audit call would log actions that never happened.
 */
it('records nothing when the underlying service failed', function () {
    ($this->as)()->delete(route('admin.setting.role.destroy', 999999));

    expect(AuditLog::where('action', 'role.deleted')->count())->toBe(0);
});

// Replaying a webhook moves billing state, so it is a staff action.
it('records a webhook being retried', function () {
    $event = \App\Models\WebhookEvent::create([
        'provider' => 'dodo',
        'event_id' => 'msg_x',
        'event_type' => 'subscription.active',
        'payload' => ['type' => 'subscription.active', 'data' => []],
        'signature_verified' => true,
        'occurred_at' => now(),
        'received_at' => now(),
        'failed_at' => now(),
    ]);

    ($this->as)()->post(route('admin.billing-ops.retry', $event));

    expect(AuditLog::where('action', 'webhook.retried')->exists())->toBeTrue();
});

// ------------------------------------------------------------- reading it

it('separates what we did as them from what they did', function () {
    $support = AdminUser::factory()->create();
    $support->assignRole('support');

    $this->actingAs($support, 'admin')
        ->post(route('admin.customer.impersonate', $this->workspace), [
            'user_id' => $this->owner->id,
            'reason' => 'Reproducing the export bug',
        ]);

    $started = collect(app(AuditViewContract::class)->search(null, null, 50)->items())
        ->map(fn (AuditLog $log) => app(AuditViewContract::class)->present($log))
        ->firstWhere('action', 'impersonation.started');

    expect($started['via_impersonation'])->toBeTrue()
        ->and($started['actor_kind'])->toBe('staff')
        ->and($started['actor'])->toBe($support->name);
});

it('labels a plain staff action as not impersonated', function () {
    ($this->as)()->post(route('admin.customer.suspend', $this->workspace), ['reason' => 'Spam']);

    $log = AuditLog::where('action', 'workspace.suspended')->firstOrFail();

    expect(app(AuditViewContract::class)->present($log)['via_impersonation'])->toBeFalse();
});

it('finds the trail by customer name', function () {
    ($this->as)()->post(route('admin.customer.suspend', $this->workspace), ['reason' => 'Spam']);

    ($this->as)()->getJson(route('admin.audit.fetch', ['filter' => ['search' => 'Acme']]))
        ->assertOk()
        ->assertJsonPath('items.0.action', 'workspace.suspended')
        ->assertJsonPath('items.0.workspace_name', 'Acme Inc');
});

it('filters the trail by action', function () {
    ($this->as)()->post(route('admin.customer.suspend', $this->workspace), ['reason' => 'Spam']);
    ($this->as)()->delete(route('admin.customer.unsuspend', $this->workspace));

    ($this->as)()->getJson(route('admin.audit.fetch', ['filter' => ['action' => 'workspace.unsuspended']]))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.action', 'workspace.unsuspended');
});

it('lists impersonation sessions with the open one flagged', function () {
    $support = AdminUser::factory()->create();
    $support->assignRole('support');

    $this->actingAs($support, 'admin')
        ->post(route('admin.customer.impersonate', $this->workspace), [
            'user_id' => $this->owner->id, 'reason' => 'Support',
        ]);

    $sessions = app(AuditViewContract::class)->impersonations(null);

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]['is_active'])->toBeTrue()
        ->and($sessions[0]['reason'])->toBe('Support')
        ->and($sessions[0]['workspace_name'])->toBe('Acme Inc');
});

it('shows the screen to staff who answer tickets', function () {
    ($this->as)()->get(route('admin.audit.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/audit/index')
            ->has('actions')
            ->has('impersonations'));
});

it('keeps customers out of the trail', function () {
    $this->actingAs($this->owner)
        ->get(route('admin.audit.index'))
        ->assertRedirect(route('admin.login'));
});
