<?php

use App\Contract\Admin\StaffContract;
use App\Exceptions\Domain\StaffLockout;
use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * Section 3: platform staff on their own guard, with runtime-editable roles.
 *
 * `staff.manage` was seeded from the start with nothing behind it, so adding or
 * removing a colleague meant editing a seeder and deploying - for a console
 * that can suspend workspaces, comp plans and enter customer accounts.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);

    $this->staff = app(StaffContract::class);

    $this->super = AdminUser::factory()->create(['name' => 'Root']);
    $this->super->assignRole('super-admin');

    $this->support = AdminUser::factory()->create(['name' => 'Sam Support']);
    $this->support->assignRole('support');
});

// ------------------------------------------------------------------ the CRUD

it('creates a staff account with a role', function () {
    $this->actingAs($this->super, 'admin')
        ->post(route('admin.staff.store'), [
            'name' => 'New Hire',
            'email' => 'hire@example.com',
            'password' => 'Str0ng-password!',
            'role' => 'support',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $created = AdminUser::where('email', 'hire@example.com')->firstOrFail();

    expect($created->hasRole('support'))->toBeTrue()
        ->and($created->is_active)->toBeTrue()
        // The point of the separate table: this account is not a customer.
        ->and(User::where('email', 'hire@example.com')->exists())->toBeFalse();
});

it('lets the new account actually sign in', function () {
    $this->actingAs($this->super, 'admin')->post(route('admin.staff.store'), [
        'name' => 'New Hire',
        'email' => 'hire@example.com',
        'password' => 'Str0ng-password!',
        'role' => 'support',
    ]);

    // admin.attempt is guest:admin, so the creator has to leave first.
    auth()->guard('admin')->logout();

    $this->post(route('admin.attempt'), [
        'email' => 'hire@example.com',
        'password' => 'Str0ng-password!',
    ])->assertRedirect(route('admin.dashboard'));
});

it('refuses a role from the customer guard', function () {
    $this->actingAs($this->super, 'admin')
        ->post(route('admin.staff.store'), [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'Str0ng-password!',
            'role' => 'not-a-staff-role',
        ])
        ->assertSessionHasErrors('role');
});

// Blank means "leave it alone", or saving a name change would wipe the password.
it('keeps the existing password when the field is left blank', function () {
    $this->support->update(['password' => Hash::make('original-password')]);

    $this->actingAs($this->super, 'admin')
        ->put(route('admin.staff.update', $this->support), [
            'name' => 'Sam Renamed',
            'email' => $this->support->email,
            'password' => '',
            'role' => 'support',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect($this->support->fresh()->name)->toBe('Sam Renamed')
        ->and(Hash::check('original-password', $this->support->fresh()->password))->toBeTrue();
});

it('changes a role at runtime', function () {
    $this->actingAs($this->super, 'admin')
        ->put(route('admin.staff.update', $this->support), [
            'name' => $this->support->name,
            'email' => $this->support->email,
            'role' => 'finance',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect($this->support->fresh()->hasRole('finance'))->toBeTrue()
        ->and($this->support->fresh()->hasRole('support'))->toBeFalse();
});

// --------------------------------------------------------------- offboarding

/*
 * Soft delete, never hard: impersonation_sessions and audit_logs reference
 * admin_users with restrictOnDelete precisely so a leaver's history survives.
 */
it('offboards without erasing the history', function () {
    $this->actingAs($this->super, 'admin')
        ->delete(route('admin.staff.destroy', $this->support))
        ->assertRedirect();

    $offboarded = AdminUser::withTrashed()->find($this->support->id);

    expect($offboarded)->not->toBeNull()
        ->and($offboarded->trashed())->toBeTrue()
        ->and($offboarded->is_active)->toBeFalse();
});

it('stops an offboarded account signing in', function () {
    $this->support->update(['password' => Hash::make('still-known')]);

    $this->actingAs($this->super, 'admin')->delete(route('admin.staff.destroy', $this->support));

    auth()->guard('admin')->logout();

    $this->post(route('admin.attempt'), [
        'email' => $this->support->email,
        'password' => 'still-known',
    ])->assertSessionHasErrors('email');

    expect(auth()->guard('admin')->check())->toBeFalse();
});

it('stops a deactivated account signing in', function () {
    $this->support->update(['password' => Hash::make('still-known')]);

    $this->actingAs($this->super, 'admin')->put(route('admin.staff.update', $this->support), [
        'name' => $this->support->name,
        'email' => $this->support->email,
        'role' => 'support',
        'is_active' => false,
    ])->assertRedirect();

    auth()->guard('admin')->logout();

    $this->post(route('admin.attempt'), [
        'email' => $this->support->email,
        'password' => 'still-known',
    ])->assertSessionHasErrors('email');
});

// ------------------------------------------------------------- the lockouts

/*
 * Two ways to lose the console permanently, both easy to do by accident and
 * neither recoverable without a deploy - which is what this screen exists to
 * stop needing.
 */
it('refuses to let somebody deactivate themselves', function () {
    expect(fn () => $this->staff->update(
        $this->super,
        ['name' => 'Root', 'email' => $this->super->email, 'is_active' => false],
        'super-admin',
        $this->super,
    ))->toThrow(StaffLockout::class);

    expect($this->super->fresh()->is_active)->toBeTrue();
});

it('refuses to let somebody offboard themselves', function () {
    expect(fn () => $this->staff->offboard($this->super, $this->super))
        ->toThrow(StaffLockout::class);

    expect($this->super->fresh()->trashed())->toBeFalse();
});

it('refuses to offboard the last super-admin', function () {
    $second = AdminUser::factory()->create();
    $second->assignRole('super-admin');

    // Two exist, so removing one is fine.
    $this->staff->offboard($this->super, $second);
    expect($this->super->fresh()->trashed())->toBeTrue();

    // Now `second` is the only one left, and support cannot grant the role back.
    expect(fn () => $this->staff->offboard($second, $this->support))
        ->toThrow(StaffLockout::class);

    expect($second->fresh()->trashed())->toBeFalse();
});

// The easier mistake: not removing them, just moving them off the role.
it('refuses to move the last super-admin onto another role', function () {
    expect(fn () => $this->staff->update(
        $this->super,
        ['name' => 'Root', 'email' => $this->super->email, 'is_active' => true],
        'finance',
        $this->support,
    ))->toThrow(StaffLockout::class);

    expect($this->super->fresh()->hasRole('super-admin'))->toBeTrue();
});

// A deactivated super-admin is not a way back in, so it must not count.
it('does not count a deactivated super-admin as cover', function () {
    $spare = AdminUser::factory()->create(['is_active' => false]);
    $spare->assignRole('super-admin');

    expect(fn () => $this->staff->offboard($this->super, $this->support))
        ->toThrow(StaffLockout::class);
});

it('allows the change once a second active super-admin exists', function () {
    $second = AdminUser::factory()->create();
    $second->assignRole('super-admin');

    $this->staff->update(
        $this->super,
        ['name' => 'Root', 'email' => $this->super->email, 'is_active' => true],
        'finance',
        $second,
    );

    expect($this->super->fresh()->hasRole('finance'))->toBeTrue();
});

// -------------------------------------------------------------------- access

it('lists staff including those already offboarded', function () {
    $this->staff->offboard($this->support, $this->super);

    $this->actingAs($this->super, 'admin')
        ->getJson(route('admin.staff.fetch'))
        ->assertOk()
        ->assertJsonFragment(['email' => $this->support->email, 'is_offboarded' => true]);
});

it('refuses staff without the manage permission', function () {
    $this->actingAs($this->support, 'admin')
        ->get(route('admin.staff.index'))
        ->assertForbidden();
});

it('keeps customers out entirely', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.staff.index'))
        ->assertRedirect(route('admin.login'));
});
