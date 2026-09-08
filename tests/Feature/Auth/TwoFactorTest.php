<?php

use App\Contract\Auth\TwoFactorContract;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->withoutVite();
    $this->service = app(TwoFactorContract::class);
    $this->google2fa = app(Google2FA::class);

    $this->user = User::factory()->create([
        'email' => 'customer@example.com',
        'password' => Hash::make('correct-horse'),
    ]);

    RateLimiter::clear('customer@example.com|127.0.0.1');
});

/** Enrol and confirm, returning the current valid code generator. */
function enrol(object $account): string
{
    app(TwoFactorContract::class)->beginEnrolment($account);
    $account->refresh();
    $code = app(Google2FA::class)->getCurrentOtp($account->two_factor_secret);
    app(TwoFactorContract::class)->confirm($account, $code);
    $account->refresh();

    return $account->two_factor_secret;
}

// --------------------------------------------------------------- enrolment

it('does not enable anything until a code is confirmed', function () {
    $this->service->beginEnrolment($this->user);
    $this->user->refresh();

    expect($this->user->two_factor_secret)->not->toBeNull()
        ->and($this->user->hasPendingTwoFactor())->toBeTrue()
        ->and($this->user->hasTwoFactorEnabled())->toBeFalse();
});

/*
 * The reason confirmation is a separate step. If issuing a secret enabled the
 * gate, anyone who opened the setup page and closed it would be locked out of
 * their own account by a secret no authenticator ever received.
 */
it('lets an abandoned enrolment log in normally', function () {
    $this->service->beginEnrolment($this->user);

    $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
    ]);

    expect(auth()->guard('web')->check())->toBeTrue();
});

it('refuses to confirm with a wrong code', function () {
    $this->service->beginEnrolment($this->user);

    expect($this->service->confirm($this->user->refresh(), '000000'))->toBeFalse()
        ->and($this->user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('issues eight single-use recovery codes', function () {
    enrol($this->user);

    expect($this->user->twoFactorRecoveryCodes())->toHaveCount(8);
});

it('never exposes the secret or the codes when the model is serialised', function () {
    enrol($this->user);

    $array = $this->user->fresh()->toArray();

    expect($array)->not->toHaveKey('two_factor_secret')
        ->and($array)->not->toHaveKey('two_factor_recovery_codes');
});

it('stores the secret encrypted at rest', function () {
    enrol($this->user);

    $raw = DB::table('users')->where('id', $this->user->id)->value('two_factor_secret');

    expect($raw)->not->toBe($this->user->fresh()->two_factor_secret)
        ->and($raw)->not->toContain($this->user->fresh()->two_factor_secret);
});

// ------------------------------------------------------- the login challenge

it('stops short of a session when a second factor is enrolled', function () {
    enrol($this->user);

    $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
    ])->assertRedirect(route('two-factor.challenge'));

    // The whole point: the password was right and it bought nothing.
    expect(auth()->guard('web')->check())->toBeFalse();
});

it('completes the login once a valid code is given', function () {
    $secret = enrol($this->user);

    $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
    ]);

    $this->post(route('two-factor.challenge.store'), [
        'code' => $this->google2fa->getCurrentOtp($secret),
    ])->assertRedirect(route('dashboard'));

    expect(auth()->guard('web')->check())->toBeTrue()
        ->and(auth()->guard('web')->id())->toBe($this->user->id);
});

it('refuses a wrong code and starts no session', function () {
    enrol($this->user);

    $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
    ]);

    $this->post(route('two-factor.challenge.store'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect(auth()->guard('web')->check())->toBeFalse();
});

// Reaching the code prompt without proving a password first must give nothing.
it('cannot be reached without passing the password step', function () {
    enrol($this->user);

    $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));

    $this->post(route('two-factor.challenge.store'), [
        'code' => $this->google2fa->getCurrentOtp($this->user->two_factor_secret),
    ])->assertRedirect(route('login'));

    expect(auth()->guard('web')->check())->toBeFalse();
});

/*
 * Six digits is a million possibilities, and the password limiter has already
 * been cleared by the time we get here. Without a limiter of its own, a correct
 * password would buy unlimited guesses.
 */
it('throttles repeated wrong codes', function () {
    enrol($this->user);

    $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
    ]);

    foreach (range(1, 5) as $ignored) {
        $this->post(route('two-factor.challenge.store'), ['code' => '000000']);
    }

    $this->post(route('two-factor.challenge.store'), [
        'code' => $this->google2fa->getCurrentOtp($this->user->two_factor_secret),
    ])->assertSessionHasErrors('code');

    expect(auth()->guard('web')->check())->toBeFalse();
});

it('accepts a recovery code and spends it', function () {
    enrol($this->user);
    $recovery = $this->user->twoFactorRecoveryCodes()[0];

    $this->post(route('attempt'), [
        'email' => 'customer@example.com',
        'password' => 'correct-horse',
    ]);

    $this->post(route('two-factor.challenge.store'), ['code' => $recovery])
        ->assertRedirect(route('dashboard'));

    expect(auth()->guard('web')->check())->toBeTrue()
        ->and($this->user->fresh()->twoFactorRecoveryCodes())->toHaveCount(7)
        ->and($this->user->fresh()->twoFactorRecoveryCodes())->not->toContain($recovery);
});

it('will not take the same recovery code twice', function () {
    enrol($this->user);
    $recovery = $this->user->twoFactorRecoveryCodes()[0];

    $this->post(route('attempt'), ['email' => 'customer@example.com', 'password' => 'correct-horse']);
    $this->post(route('two-factor.challenge.store'), ['code' => $recovery]);
    $this->post(route('logout'));

    $this->post(route('attempt'), ['email' => 'customer@example.com', 'password' => 'correct-horse']);
    $this->post(route('two-factor.challenge.store'), ['code' => $recovery])
        ->assertSessionHasErrors('code');

    expect(auth()->guard('web')->check())->toBeFalse();
});

it('clears the pending challenge when abandoned', function () {
    enrol($this->user);

    $this->post(route('attempt'), ['email' => 'customer@example.com', 'password' => 'correct-horse']);
    $this->delete(route('two-factor.challenge.abandon'))->assertRedirect(route('login'));

    $this->post(route('two-factor.challenge.store'), [
        'code' => $this->google2fa->getCurrentOtp($this->user->two_factor_secret),
    ])->assertRedirect(route('login'));

    expect(auth()->guard('web')->check())->toBeFalse();
});

// -------------------------------------------------------------------- staff

it('challenges a staff login too', function () {
    $admin = AdminUser::factory()->create([
        'email' => 'staff@example.com',
        'password' => Hash::make('correct-horse'),
    ]);
    $secret = enrol($admin);

    $this->post(route('admin.attempt'), [
        'email' => 'staff@example.com',
        'password' => 'correct-horse',
    ])->assertRedirect(route('admin.two-factor.challenge'));

    expect(auth()->guard('admin')->check())->toBeFalse();

    $this->post(route('admin.two-factor.challenge.store'), [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertRedirect(route('admin.dashboard'));

    expect(auth()->guard('admin')->check())->toBeTrue()
        ->and($admin->fresh()->last_login_at)->not->toBeNull();
});

/*
 * Staff can be deactivated between proving a password and entering a code. The
 * second half must recheck it rather than trust a test that has since stopped
 * being true.
 */
it('refuses a staff member deactivated mid-challenge', function () {
    $admin = AdminUser::factory()->create([
        'email' => 'staff@example.com',
        'password' => Hash::make('correct-horse'),
    ]);
    $secret = enrol($admin);

    $this->post(route('admin.attempt'), [
        'email' => 'staff@example.com',
        'password' => 'correct-horse',
    ]);

    $admin->update(['is_active' => false]);

    $this->post(route('admin.two-factor.challenge.store'), [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertRedirect(route('admin.login'));

    expect(auth()->guard('admin')->check())->toBeFalse();
});

// -------------------------------------------------------------- settings UI

it('requires a confirmed password before turning two-factor on', function () {
    $this->actingAs($this->user)
        ->post(route('two-factor.store'))
        ->assertRedirect(route('password.confirm'));

    expect($this->user->fresh()->two_factor_secret)->toBeNull();
});

it('turns two-factor on and off from settings', function () {
    $this->actingAs($this->user);
    $this->session(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.store'))->assertRedirect(route('two-factor.edit'));

    $secret = $this->user->fresh()->two_factor_secret;

    $this->post(route('two-factor.confirm'), [
        'code' => $this->google2fa->getCurrentOtp($secret),
    ])->assertRedirect(route('two-factor.edit'));

    expect($this->user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->delete(route('two-factor.destroy'))->assertRedirect(route('two-factor.edit'));

    expect($this->user->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($this->user->fresh()->two_factor_secret)->toBeNull();
});

it('only sends the secret to the page while enrolment is pending', function () {
    $this->actingAs($this->user);

    // Nothing started: nothing to show.
    $this->get(route('two-factor.edit'))
        ->assertInertia(fn ($page) => $page->where('qrSvg', null)->where('setupKey', null));

    $this->service->beginEnrolment($this->user);

    // Mid-enrolment: the QR and the typed key are the whole point of the page.
    $this->get(route('two-factor.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('pending', true)
            ->where('enabled', false)
            ->whereNot('qrSvg', null)
            ->whereNot('setupKey', null)
            ->etc());

    $this->service->confirm($this->user->refresh(), $this->google2fa->getCurrentOtp($this->user->two_factor_secret));

    // Confirmed: the seed has no reason to cross the wire again.
    $this->get(route('two-factor.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('enabled', true)
            ->where('qrSvg', null)
            ->where('setupKey', null)
            ->etc());
});

it('regenerates recovery codes', function () {
    enrol($this->user);
    $before = $this->user->twoFactorRecoveryCodes();

    $this->actingAs($this->user);
    $this->session(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.recovery-codes'))->assertRedirect(route('two-factor.edit'));

    expect($this->user->fresh()->twoFactorRecoveryCodes())->toHaveCount(8)
        ->and($this->user->fresh()->twoFactorRecoveryCodes())->not->toBe($before);
});
