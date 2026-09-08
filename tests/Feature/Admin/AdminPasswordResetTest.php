<?php

use App\Models\AdminUser;
use App\Models\User;
use App\Notifications\AdminPasswordResetNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
 * Section 3 gave staff their own guard and their own table, and then no way
 * back in after a forgotten password: recovering one meant another super-admin
 * retyping it, or a deploy on an install with a single super-admin.
 *
 * The whole point is that the two worlds stay apart while doing it, so most of
 * this file is about what must NOT happen.
 */

beforeEach(function () {
    $this->withoutVite();
    Notification::fake();

    $this->admin = AdminUser::factory()->create([
        'email' => 'staff@example.com',
        'password' => Hash::make('old-password'),
    ]);
});

it('emails a staff member a reset link', function () {
    $this->post(route('admin.password.email'), ['email' => 'staff@example.com'])
        ->assertSessionHas('status');

    Notification::assertSentTo($this->admin, AdminPasswordResetNotification::class);
});

// The framework's own notification links to the CUSTOMER form, where the token
// is checked against the wrong table.
it('links to the staff form, not the customer one', function () {
    $this->post(route('admin.password.email'), ['email' => 'staff@example.com']);

    Notification::assertSentTo(
        $this->admin,
        AdminPasswordResetNotification::class,
        function (AdminPasswordResetNotification $notification) {
            $action = $notification->toMail($this->admin)->actionUrl;

            return str_contains($action, '/admin/reset-password')
                && ! str_contains($action, '/auth/');
        },
    );

    Notification::assertNotSentTo($this->admin, ResetPassword::class);
});

// Anything else turns this form into a directory of who works here.
it('says the same thing about an address that is not staff', function () {
    $this->post(route('admin.password.email'), ['email' => 'nobody@example.com'])
        ->assertSessionHas('status')
        ->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

it('sends nothing to a deactivated staff account', function () {
    $this->admin->update(['is_active' => false]);

    $this->post(route('admin.password.email'), ['email' => 'staff@example.com'])
        ->assertSessionHas('status');

    Notification::assertNothingSent();
});

it('sends nothing to an offboarded staff account', function () {
    $this->admin->delete();

    $this->post(route('admin.password.email'), ['email' => 'staff@example.com'])
        ->assertSessionHas('status');

    Notification::assertNothingSent();
});

// One person can hold both a staff account and a customer one on the same
// address. Neither form may reach across.
it('does not send a staff link to a customer of the same address', function () {
    $customer = User::factory()->create(['email' => 'both@example.com']);

    $this->post(route('admin.password.email'), ['email' => 'both@example.com']);

    Notification::assertNothingSent();
    expect($customer->fresh())->not->toBeNull();
});

// --------------------------------------------------------------- the reset

it('sets a new password and lets the staff member sign in with it', function () {
    $token = Password::broker('admin_users')->createToken($this->admin);

    $this->post(route('admin.password.update'), [
        'token' => $token,
        'email' => 'staff@example.com',
        'password' => 'Str0ng-new-password!',
        'password_confirmation' => 'Str0ng-new-password!',
    ])->assertRedirect(route('admin.login'));

    expect(Hash::check('Str0ng-new-password!', $this->admin->fresh()->password))->toBeTrue();

    $this->post(route('admin.attempt'), [
        'email' => 'staff@example.com',
        'password' => 'Str0ng-new-password!',
    ])->assertRedirect(route('admin.two-factor.challenge'));
});

/*
 * A new password is one factor and the console asks for two, so the reset
 * hands them to the login rather than opening the door.
 */
it('does not sign anybody in', function () {
    $token = Password::broker('admin_users')->createToken($this->admin);

    $this->post(route('admin.password.update'), [
        'token' => $token,
        'email' => 'staff@example.com',
        'password' => 'Str0ng-new-password!',
        'password_confirmation' => 'Str0ng-new-password!',
    ]);

    expect(auth()->guard('admin')->check())->toBeFalse();
});

// Tokens last an hour, and staff can be offboarded inside one.
it('refuses a token belonging to an account deactivated since it was issued', function () {
    $token = Password::broker('admin_users')->createToken($this->admin);

    $this->admin->update(['is_active' => false]);

    $this->post(route('admin.password.update'), [
        'token' => $token,
        'email' => 'staff@example.com',
        'password' => 'Str0ng-new-password!',
        'password_confirmation' => 'Str0ng-new-password!',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('old-password', $this->admin->fresh()->password))->toBeTrue();
});

it('refuses a customer token on the staff form', function () {
    $customer = User::factory()->create(['email' => 'staff@example.com']);
    $customerToken = Password::broker('users')->createToken($customer);

    $this->post(route('admin.password.update'), [
        'token' => $customerToken,
        'email' => 'staff@example.com',
        'password' => 'Str0ng-new-password!',
        'password_confirmation' => 'Str0ng-new-password!',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('old-password', $this->admin->fresh()->password))->toBeTrue();
});

it('refuses a staff token on the customer form', function () {
    $customer = User::factory()->create([
        'email' => 'staff@example.com',
        'password' => Hash::make('customer-password'),
    ]);
    $staffToken = Password::broker('admin_users')->createToken($this->admin);

    $this->post(route('password.update'), [
        'token' => $staffToken,
        'email' => 'staff@example.com',
        'password' => 'Str0ng-new-password!',
        'password_confirmation' => 'Str0ng-new-password!',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('customer-password', $customer->fresh()->password))->toBeTrue();
});

it('keeps the forms out of reach of a signed in staff member', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.password.request'))
        ->assertRedirect();
});

it('throttles repeated link requests', function () {
    foreach (range(1, 6) as $ignored) {
        $this->post(route('admin.password.email'), ['email' => 'staff@example.com']);
    }

    $this->post(route('admin.password.email'), ['email' => 'staff@example.com'])
        ->assertStatus(429);
});
