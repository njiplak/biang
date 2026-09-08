<?php

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->withoutVite();
    RateLimiter::clear('staff@example.com|127.0.0.1');

    $this->admin = AdminUser::factory()->create([
        'email' => 'staff@example.com',
        'password' => Hash::make('correct-horse'),
    ]);
});

it('configures an admin guard that can actually authenticate', function () {
    expect(fn () => auth()->guard('admin')->check())->not->toThrow(InvalidArgumentException::class);
});

it('signs a staff member in', function () {
    $this->post(route('admin.attempt'), [
        'email' => 'staff@example.com',
        'password' => 'correct-horse',
    ])->assertRedirect();

    expect(auth()->guard('admin')->check())->toBeTrue()
        ->and(auth()->guard('admin')->id())->toBe($this->admin->id);
});

it('rejects a wrong password', function () {
    $this->post(route('admin.attempt'), [
        'email' => 'staff@example.com',
        'password' => 'wrong',
    ])->assertSessionHasErrors();

    expect(auth()->guard('admin')->check())->toBeFalse();
});

// A deactivated staff member is the offboarding path - the row stays for the
// audit trail, but the login has to stop working immediately.
it('refuses a deactivated staff account', function () {
    $this->admin->update(['is_active' => false]);

    $this->post(route('admin.attempt'), [
        'email' => 'staff@example.com',
        'password' => 'correct-horse',
    ])->assertSessionHasErrors();

    expect(auth()->guard('admin')->check())->toBeFalse();
});

it('refuses a soft deleted staff account', function () {
    $this->admin->delete();

    $this->post(route('admin.attempt'), [
        'email' => 'staff@example.com',
        'password' => 'correct-horse',
    ])->assertSessionHasErrors();

    expect(auth()->guard('admin')->check())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Section 3: "A customer account can never reach admin functions." These four
// are the whole point of a separate table and guard.
// ---------------------------------------------------------------------------

it('refuses customer credentials at the admin login', function () {
    User::factory()->create(['email' => 'customer@example.com', 'password' => Hash::make('secret-pass')]);

    $this->post(route('admin.attempt'), [
        'email' => 'customer@example.com',
        'password' => 'secret-pass',
    ])->assertSessionHasErrors();

    expect(auth()->guard('admin')->check())->toBeFalse();
});

it('refuses staff credentials at the customer login', function () {
    $this->post(route('attempt'), [
        'email' => 'staff@example.com',
        'password' => 'correct-horse',
    ])->assertSessionHasErrors();

    expect(auth()->guard('web')->check())->toBeFalse();
});

it('does not make a signed in customer an admin', function () {
    $customer = User::factory()->create();

    $this->actingAs($customer)->get('/admin');

    expect(auth()->guard('admin')->check())->toBeFalse();
});

it('sends a guest hitting an admin page to the admin login, not the customer one', function () {
    $this->get('/admin')->assertRedirect(route('admin.login'));
});

// Both guards live in one session on purpose: impersonation needs the staff
// member to stay authenticated as staff while acting as the customer.
it('allows both guards to hold a session at once', function () {
    $customer = User::factory()->create();

    $this->actingAs($this->admin, 'admin')->actingAs($customer, 'web');

    expect(auth()->guard('admin')->check())->toBeTrue()
        ->and(auth()->guard('web')->check())->toBeTrue()
        ->and(auth()->guard('admin')->user())->toBeInstanceOf(AdminUser::class)
        ->and(auth()->guard('web')->user())->toBeInstanceOf(User::class);
});

it('signs a staff member out without touching the customer session', function () {
    $customer = User::factory()->create();
    $this->actingAs($this->admin, 'admin')->actingAs($customer, 'web');

    $this->post(route('admin.logout'))->assertRedirect();

    expect(auth()->guard('admin')->check())->toBeFalse()
        ->and(auth()->guard('web')->check())->toBeTrue();
});

// Both logins share LoginRequest's limiter; the customer side is covered in
// tests/Feature/Auth/LoginTest.php.
it('throttles repeated failed admin logins', function () {
    foreach (range(1, 5) as $ignored) {
        $this->post(route('admin.attempt'), [
            'email' => 'staff@example.com',
            'password' => 'wrong',
        ]);
    }

    $this->post(route('admin.attempt'), [
        'email' => 'staff@example.com',
        'password' => 'correct-horse',
    ])->assertSessionHasErrors('email');

    expect(auth()->guard('admin')->check())->toBeFalse();
});

/*
 * admin_users has carried last_login_at and last_login_ip since the table was
 * created and nothing wrote them. Who was in the console and when is the first
 * question asked when staff are offboarded, and it cannot be reconstructed
 * after the fact.
 */
it('records when and from where a staff member signed in', function () {
    $admin = AdminUser::factory()->create(['password' => Hash::make('secret-password')]);

    expect($admin->last_login_at)->toBeNull();

    $this->post(route('admin.attempt'), [
        'email' => $admin->email,
        'password' => 'secret-password',
    ])->assertRedirect(route('admin.dashboard'));

    $fresh = $admin->fresh();

    expect($fresh->last_login_at)->not->toBeNull()
        ->and($fresh->last_login_ip)->not->toBeNull();
});
