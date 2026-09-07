<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/*
 * Regression: POST /auth/logout was declared inside a `guest` group with an
 * `auth` group nested in it, so the route carried BOTH. RedirectIfAuthenticated
 * runs first and bounced every signed-in customer to /dashboard, which meant
 * logging out was impossible. Nothing covered it, which is how it survived.
 */

beforeEach(fn () => $this->withoutVite());

it('logs a customer out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('does not carry the guest middleware that made logging out impossible', function () {
    $middleware = collect(app('router')->getRoutes()->getByName('logout')->gatherMiddleware());

    expect($middleware)->toContain('auth')
        ->and($middleware)->not->toContain('guest');
});

// The way in still has to be guest-only, or a signed-in person could re-login
// as somebody else without leaving first.
it('keeps the login page guest only', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('dashboard', absolute: false));
});

it('leaves a signed-out visitor at the login page', function () {
    $this->get(route('login'))->assertOk();
});
