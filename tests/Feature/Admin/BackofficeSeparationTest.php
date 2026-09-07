<?php

use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);
});

// Section 3: "A customer account can never reach admin functions."
it('keeps a signed in customer out of the backoffice', function () {
    $this->actingAs(User::factory()->create())
        ->get('/backoffice')
        ->assertRedirect(route('admin.login'));
});

it('keeps a signed in customer out of every settings screen', function (string $path) {
    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertRedirect(route('admin.login'));
})->with([
    '/setting/setting',
    '/setting/role',
    '/setting/permission',
    '/setting/user',
]);

it('lets a super-admin into the backoffice', function () {
    $admin = AdminUser::factory()->create();
    $admin->assignRole('super-admin');

    $this->actingAs($admin, 'admin')->get('/backoffice')->assertOk();
});

it('lets a super-admin into the settings screens', function () {
    $admin = AdminUser::factory()->create();
    $admin->assignRole('super-admin');

    $this->actingAs($admin, 'admin')->get('/setting/role')->assertOk();
});

// Staff RBAC still applies inside the console - being staff is not enough.
it('refuses a staff member without the permission', function () {
    $admin = AdminUser::factory()->create();
    $admin->assignRole('finance');

    $this->actingAs($admin, 'admin')->get('/setting/role')->assertForbidden();
});

it('no longer dumps customers into the backoffice after login', function () {
    $user = User::factory()->create();

    // hitting a guest-only page while signed in should not land on /backoffice
    $this->actingAs($user)->get(route('login'))->assertRedirect(route('dashboard', absolute: false));
});
