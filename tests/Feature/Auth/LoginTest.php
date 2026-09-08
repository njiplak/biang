<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->withoutVite();
    RateLimiter::clear('customer@example.com|127.0.0.1');
    RateLimiter::clear('nobody@example.com|127.0.0.1');

    $this->customer = User::factory()->create([
        'email' => 'customer@example.com',
        'password' => Hash::make('correct-horse'),
    ]);
});

it('signs a customer in', function () {
    $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
    ]);

    expect(auth()->guard('web')->check())->toBeTrue()
        ->and(auth()->guard('web')->id())->toBe($this->customer->id);
});

it('rejects a wrong password', function () {
    $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'wrong',
    ])->assertSessionHasErrors('email');

    expect(auth()->guard('web')->check())->toBeFalse();
});

/*
 * The point of the rewrite. Two different messages - "Email is not registered."
 * against "Incorrect password." - let anyone with the login form confirm which
 * of our customers' addresses exist, one guess at a time.
 *
 * Scope: this covers the wording only. The timing difference between the two is
 * still there and is not asserted here - see AuthService::login().
 */
it('says the same thing for an unknown email as for a wrong password', function () {
    $unknown = $this->post(route('attempt'), [
        'email' => 'nobody@example.com',
        'password' => 'correct-horse',
    ]);

    $wrongPassword = $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'wrong',
    ]);

    expect($unknown->exception)->toBeNull()
        ->and(session('errors')->get('email'))->not->toBeEmpty();

    $unknownMessage = $unknown->getSession()->get('errors')->get('email');
    $wrongMessage = $wrongPassword->getSession()->get('errors')->get('email');

    expect($unknownMessage)->toBe($wrongMessage)
        ->and($unknownMessage[0])->not->toContain('not registered');
});

it('throttles repeated failed customer logins', function () {
    foreach (range(1, 5) as $ignored) {
        $this->post(route('attempt'), [
            'email' => 'customer@example.com',
            'password' => 'wrong',
        ]);
    }

    // The sixth is refused even though the password is now right.
    $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
    ])->assertSessionHasErrors('email');

    expect(auth()->guard('web')->check())->toBeFalse();
});

it('clears the limiter once a login succeeds', function () {
    foreach (range(1, 4) as $ignored) {
        $this->post(route('attempt'), [
            'email' => 'customer@example.com',
            'password' => 'wrong',
        ]);
    }

    $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
    ]);

    expect(auth()->guard('web')->check())->toBeTrue()
        ->and(RateLimiter::attempts('customer@example.com|127.0.0.1'))->toBe(0);
});

/*
 * Session fixation: a session id handed to a visitor BEFORE they sign in must
 * not still identify them afterwards, or anyone who planted that id is now
 * signed in as them.
 *
 * This passed before the throttle work too - SessionGuard::updateSession()
 * regenerates the id itself. It is pinned here so that a future rewrite of
 * AuthService::login that stops going through the guard cannot quietly drop it.
 */
it('regenerates the session id on login', function () {
    $this->get(route('login'));
    $before = session()->getId();

    $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
    ]);

    expect(session()->getId())->not->toBe($before)
        ->and(auth()->guard('web')->check())->toBeTrue();
});

/*
 * "Remember me" rendered, submitted, and did nothing: LoginRequest had no rule
 * for `remember`, so validated() dropped it before the service could read it.
 */
it('issues a recaller cookie when remember me is ticked', function () {
    $response = $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
        'remember' => true,
    ]);

    $response->assertCookie(Auth::guard('web')->getRecallerName());
});

it('issues no recaller cookie when remember me is left alone', function () {
    $response = $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
        'remember' => false,
    ]);

    $response->assertCookieMissing(Auth::guard('web')->getRecallerName());
});

// The two links the login page had been missing. Guests must be able to reach
// both, or the links are decoration.
it('lets a guest reach the pages the login page now links to', function () {
    $this->get(route('register'))->assertOk();
    $this->get(route('password.request'))->assertOk();
});
