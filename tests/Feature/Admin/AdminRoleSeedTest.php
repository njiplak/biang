<?php

use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(AdminRoleSeeder::class);
});

it('seeds staff roles on the admin guard only', function () {
    expect(Role::where('guard_name', 'admin')->count())->toBeGreaterThan(0)
        ->and(Permission::where('guard_name', 'admin')->count())->toBeGreaterThan(0)
        // section 3: the customer guard must be untouched by staff RBAC
        ->and(Role::where('guard_name', 'web')->count())->toBe(0);
});

it('gives super-admin every staff permission', function () {
    $superAdmin = Role::where('name', 'super-admin')->where('guard_name', 'admin')->first();

    expect($superAdmin->permissions()->count())
        ->toBe(Permission::where('guard_name', 'admin')->count());
});

// A missing permission must never lock the team out of their own console.
it('lets a super-admin through any gate', function () {
    $admin = AdminUser::factory()->create();
    $admin->assignRole('super-admin');

    expect(Gate::forUser($admin)->allows('some.permission.that.does.not.exist'))->toBeTrue();
});

it('does not give that bypass to other staff roles', function () {
    $admin = AdminUser::factory()->create();
    $admin->assignRole('support');

    expect(Gate::forUser($admin)->allows('some.permission.that.does.not.exist'))->toBeFalse();
});

// The bypass is scoped to AdminUser instances - a customer must never inherit it.
it('never applies the bypass to a customer', function () {
    $customer = User::factory()->create();

    expect(Gate::forUser($customer)->allows('some.permission.that.does.not.exist'))->toBeFalse();
});

// Section 3's split, as permissions: support answers tickets, finance sees
// money, and neither gets the other's reach.
it('scopes support away from billing grants', function () {
    $support = Role::where('name', 'support')->where('guard_name', 'admin')->first();

    expect($support->hasPermissionTo('customer.view'))->toBeTrue()
        ->and($support->hasPermissionTo('billing.grant'))->toBeFalse();
});

it('gives sales the ability to grant a plan by hand', function () {
    $sales = Role::where('name', 'sales')->where('guard_name', 'admin')->first();

    expect($sales->hasPermissionTo('billing.grant'))->toBeTrue()
        ->and($sales->hasPermissionTo('billing.override'))->toBeTrue();
});

it('is idempotent', function () {
    $roles = Role::count();
    $permissions = Permission::count();

    $this->seed(AdminRoleSeeder::class);
    $this->seed(AdminRoleSeeder::class);

    expect(Role::count())->toBe($roles)
        ->and(Permission::count())->toBe($permissions);
});
