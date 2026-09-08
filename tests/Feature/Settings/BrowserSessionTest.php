<?php

use App\Contract\Auth\BrowserSessionContract;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * "Where am I signed in, and get me out of everywhere else."
 *
 * The account pages now offer a second factor, and a second factor with no way
 * to end a session somebody already has is half a control.
 *
 * The hard part is section 3. Staff and customers share one session on purpose
 * so impersonation works, and Laravel stamps `user_id` from the DEFAULT guard
 * (`web`) - so a staff member impersonating writes a row carrying the
 * CUSTOMER's id. Left alone this screen hands the customer a staff member's IP
 * address and a button that ends a support session mid-ticket.
 */

beforeEach(function () {
    $this->withoutVite();

    /*
     * phpunit.xml runs the suite on the `array` session driver, where nothing
     * is written down and there is nothing to list. The feature only works on
     * the `database` driver the application actually ships with (.env), so the
     * tests have to ask for it - and the degraded case gets its own test at the
     * bottom rather than being the accidental default everywhere.
     */
    config(['session.driver' => 'database']);

    $this->user = User::factory()->create();
    $this->sessions = app(BrowserSessionContract::class);
});

/** A row exactly as the database session driver writes one. */
function writeSession(int $userId, array $attributes = [], array $overrides = []): string
{
    $id = $overrides['id'] ?? (string) Str::random(40);

    DB::table('sessions')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X) Chrome/120 Safari/537',
        'payload' => base64_encode(serialize($attributes)),
        'last_activity' => now()->timestamp,
    ], $overrides));

    return $id;
}

// ------------------------------------------------------------- listing

it('lists the devices this person is signed in on', function () {
    writeSession($this->user->id, [], ['id' => 'session-a']);
    writeSession($this->user->id, [], ['id' => 'session-b', 'ip_address' => '198.51.100.4']);

    $listed = $this->sessions->forUser($this->user, 'session-a');

    expect($listed)->toHaveCount(2)
        ->and(collect($listed)->firstWhere('is_current', true)['ip_address'])->toBe('203.0.113.10');
});

it('never shows another account a session', function () {
    writeSession($this->user->id, [], ['id' => 'mine']);
    writeSession(User::factory()->create()->id, [], ['id' => 'theirs']);

    expect($this->sessions->forUser($this->user, 'mine'))->toHaveCount(1);
});

/*
 * The session id is the bearer token for that session. Printing it on a page
 * turns any XSS into account takeover on every device listed, so it must not
 * leave the server at all.
 */
it('does not hand the session id to the browser', function () {
    writeSession($this->user->id, [], ['id' => 'secret-session-id']);

    $listed = $this->sessions->forUser($this->user, 'secret-session-id');

    expect(json_encode($listed))->not->toContain('secret-session-id')
        ->and(array_keys($listed[0]))->toEqualCanonicalizing([
            'ip_address', 'user_agent', 'last_active_at', 'is_current',
        ]);
});

// ------------------------------------------------------------- impersonation

/*
 * The whole reason this needed a decision. The row carries the customer's
 * user_id, so without the payload check it reads as one of their own devices.
 */
it('hides a staff impersonation session from the customer', function () {
    writeSession($this->user->id, [], ['id' => 'mine']);
    writeSession(
        $this->user->id,
        [ImpersonationController::SESSION_KEY => 99],
        ['id' => 'staff', 'ip_address' => '10.0.0.9'],
    );

    $listed = $this->sessions->forUser($this->user, 'mine');

    expect($listed)->toHaveCount(1)
        ->and(collect($listed)->pluck('ip_address'))->not->toContain('10.0.0.9');
});

it('refuses to end a staff impersonation session', function () {
    writeSession($this->user->id, [], ['id' => 'mine']);
    writeSession($this->user->id, [ImpersonationController::SESSION_KEY => 99], ['id' => 'staff']);

    $ended = $this->sessions->logOutOthers($this->user, 'mine');

    expect($ended)->toBe(0)
        ->and(DB::table('sessions')->where('id', 'staff')->exists())->toBeTrue();
});

/*
 * Fails CLOSED. The two mistakes are not equal: hiding one of the customer's
 * own devices is a missing row, showing a support session leaks a staff IP.
 */
it('hides a session whose payload cannot be read', function () {
    writeSession($this->user->id, [], ['id' => 'mine']);
    writeSession($this->user->id, [], ['id' => 'broken', 'payload' => 'not-serialised-anything']);

    expect($this->sessions->forUser($this->user, 'mine'))->toHaveCount(1);
});

// ------------------------------------------------------------- ending them

it('ends every other session and keeps this one', function () {
    writeSession($this->user->id, [], ['id' => 'mine']);
    writeSession($this->user->id, [], ['id' => 'other-a']);
    writeSession($this->user->id, [], ['id' => 'other-b']);

    $ended = $this->sessions->logOutOthers($this->user, 'mine');

    expect($ended)->toBe(2)
        ->and(DB::table('sessions')->where('id', 'mine')->exists())->toBeTrue()
        ->and(DB::table('sessions')->whereIn('id', ['other-a', 'other-b'])->count())->toBe(0);
});

it('leaves other people signed in', function () {
    $stranger = User::factory()->create();
    writeSession($this->user->id, [], ['id' => 'mine']);
    writeSession($stranger->id, [], ['id' => 'theirs']);

    $this->sessions->logOutOthers($this->user, 'mine');

    expect(DB::table('sessions')->where('id', 'theirs')->exists())->toBeTrue();
});

// ------------------------------------------------------------- the screen

it('shows the screen to a signed-in customer', function () {
    $this->actingAs($this->user)
        ->get(route('sessions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/sessions')
            ->where('available', true)
            ->has('sessions'));
});

it('keeps guests out', function () {
    $this->get(route('sessions.index'))->assertRedirect('/auth/login');
});

/*
 * Behind `password.confirm` for the same reason the two-factor changes are:
 * somebody at an unlocked laptop must not be able to lock the owner out of
 * every device they own.
 */
it('asks for the password before ending anything', function () {
    writeSession($this->user->id, [], ['id' => 'other']);

    $this->actingAs($this->user)
        ->delete(route('sessions.destroy'))
        ->assertRedirect(route('password.confirm'));

    expect(DB::table('sessions')->where('id', 'other')->exists())->toBeTrue();
});

it('ends them once the password has been confirmed', function () {
    writeSession($this->user->id, [], ['id' => 'other']);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('sessions.destroy'))
        ->assertRedirect();

    expect(DB::table('sessions')->where('id', 'other')->exists())->toBeFalse();
});

/*
 * Degrading loudly. On a driver that keeps sessions somewhere unreadable the
 * screen says so, because an empty list would read as "you are signed in
 * nowhere" - the opposite of the truth.
 */
it('says so rather than showing an empty list on another driver', function () {
    config(['session.driver' => 'array']);

    $this->actingAs($this->user)
        ->get(route('sessions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('available', false)
            ->where('sessions', []));
});

it('ends nothing on a driver it cannot read', function () {
    writeSession($this->user->id, [], ['id' => 'other']);

    config(['session.driver' => 'array']);

    expect($this->sessions->logOutOthers($this->user, 'mine'))->toBe(0)
        ->and(DB::table('sessions')->where('id', 'other')->exists())->toBeTrue();
});
