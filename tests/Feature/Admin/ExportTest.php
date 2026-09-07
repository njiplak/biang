<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/* Section 14 phase 6: exports. */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->admin = AdminUser::factory()->create();
    $this->admin->assignRole('super-admin');

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

it('exports the customer directory', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.customer.export'))
        ->assertOk()
        ->assertDownload();
});

it('exports the audit trail', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.audit.export'))
        ->assertOk()
        ->assertDownload();
});

// Taking customer data out of the system is itself a staff action.
it('records the export in the audit trail', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.customer.export', ['filter' => ['search' => 'Acme']]));

    $log = AuditLog::where('action', 'customer.directory_exported')->firstOrFail();

    expect($log->actor_id)->toBe($this->admin->id)
        ->and($log->changes['search'])->toBe('Acme');
});

it('records an audit export too', function () {
    $this->actingAs($this->admin, 'admin')->get(route('admin.audit.export'));

    expect(AuditLog::where('action', 'audit.exported')->exists())->toBeTrue();
});

it('refuses staff without permission', function () {
    $finance = AdminUser::factory()->create();
    $finance->assignRole('finance');

    // finance holds customer.view, so it may export the directory...
    $this->actingAs($finance, 'admin')->get(route('admin.customer.export'))->assertOk();

    $nobody = AdminUser::factory()->create();
    $this->actingAs($nobody, 'admin')->get(route('admin.customer.export'))->assertForbidden();
});

it('keeps customers out of both exports', function () {
    $this->actingAs($this->owner)->get(route('admin.customer.export'))
        ->assertRedirect(route('admin.login'));
    $this->actingAs($this->owner)->get(route('admin.audit.export'))
        ->assertRedirect(route('admin.login'));
});
