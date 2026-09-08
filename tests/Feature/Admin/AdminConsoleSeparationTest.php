<?php

use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);
});

// Section 3: "A customer account can never reach admin functions."
it('keeps a signed in customer out of the console', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertRedirect(route('admin.login'));
});

it('keeps a signed in customer out of every settings screen', function (string $path) {
    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertRedirect(route('admin.login'));
})->with([
    '/admin/setting/setting',
    '/admin/setting/role',
    '/admin/setting/permission',
]);

it('lets a super-admin into the console', function () {
    $admin = AdminUser::factory()->create();
    $admin->assignRole('super-admin');

    $this->actingAs($admin, 'admin')->get('/admin')->assertOk();
});

it('lets a super-admin into the settings screens', function () {
    $admin = AdminUser::factory()->create();
    $admin->assignRole('super-admin');

    $this->actingAs($admin, 'admin')->get('/admin/setting/role')->assertOk();
});

// Staff RBAC still applies inside the console - being staff is not enough.
it('refuses a staff member without the permission', function () {
    $admin = AdminUser::factory()->create();
    $admin->assignRole('finance');

    $this->actingAs($admin, 'admin')->get('/admin/setting/role')->assertForbidden();
});

it('does not dump customers into the console after login', function () {
    $user = User::factory()->create();

    // hitting a guest-only page while signed in should not land on /admin
    $this->actingAs($user)->get(route('login'))->assertRedirect(route('dashboard', absolute: false));
});

/*
 * A starter-kit CRUD over the customer `users` table: it assigned spatie roles
 * that decide nothing for a customer - section 2 answers that per workspace,
 * from WorkspaceRole - and offered create and delete straight past signup and
 * past the retention path in PurgeClosedWorkspaces. Removed rather than hidden,
 * because a route nothing links to is still a route.
 *
 * The person-level view support actually needs is still owed, and is not this.
 */
it('no longer serves the starter-kit customer CRUD', function () {
    $admin = AdminUser::factory()->create();
    $admin->assignRole('super-admin');

    $this->actingAs($admin, 'admin')->get('/admin/setting/user')->assertNotFound();
});

it('leaves no user permission behind to grant', function () {
    expect(Permission::where('guard_name', 'admin')->where('name', 'LIKE', 'user.%')->exists())
        ->toBeFalse();
});

/*
 * The console is one shell on one prefix. `/backoffice` was a second entry
 * point on the same guard, and a second sidebar to keep in step with this one.
 */
it('no longer serves the old backoffice prefix', function () {
    $admin = AdminUser::factory()->create();
    $admin->assignRole('super-admin');

    $this->actingAs($admin, 'admin')->get('/backoffice')->assertNotFound();
});
