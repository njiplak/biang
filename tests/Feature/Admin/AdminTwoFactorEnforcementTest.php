<?php

use App\Models\AdminUser;
use Database\Seeders\AdminRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use PragmaRX\Google2FA\Google2FA;

/*
 * Staff reach every customer's data, can comp a plan and can enter any
 * workspace as its owner. AdminAuthController already held that "password
 * alone is not entry" - but only for the staff who had chosen to enrol, which
 * left the strongest control in the console switched off by default.
 *
 * Enrolment is self-service, so this shuts the console rather than the account.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);

    $this->unenrolled = AdminUser::factory()->withoutTwoFactor()->create([
        'password' => Hash::make('correct-horse'),
    ]);
    $this->unenrolled->assignRole('super-admin');
});

it('sends an unenrolled staff member to enrolment instead of the console', function () {
    $this->actingAs($this->unenrolled, 'admin')
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.two-factor.edit'));
});

it('covers the staff settings screens on the same rule', function () {
    $this->actingAs($this->unenrolled, 'admin')
        ->get(route('admin.setting.setting.index'))
        ->assertRedirect(route('admin.two-factor.edit'));
});

// The console's tables fetch as JSON, where a redirect reads as an empty table.
it('refuses a json fetch rather than redirecting it', function () {
    $this->actingAs($this->unenrolled, 'admin')
        ->getJson(route('admin.customer.fetch'))
        ->assertStatus(403);
});

/*
 * A redirect with no explanation looks like a broken console. The reason is
 * flashed on the request that redirects, so it can only be read on the page
 * that finally renders - which is why AdminLayout carries FlashBanner.
 */
it('says why it sent them there', function () {
    $this->actingAs($this->unenrolled, 'admin')
        ->get(route('admin.dashboard'))
        ->assertSessionHas('warning');

    $this->actingAs($this->unenrolled, 'admin')
        ->get(route('admin.two-factor.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flash.warning', 'Two-factor authentication is required for staff accounts. Set it up to continue.')
        );
});

// Without the exemption this redirects to itself, forever.
it('lets them reach the enrolment screen itself', function () {
    $this->actingAs($this->unenrolled, 'admin')
        ->get(route('admin.two-factor.edit'))
        ->assertOk();
});

// The way out must never be gated on the thing keeping them there.
it('lets them sign out', function () {
    $this->actingAs($this->unenrolled, 'admin')
        ->post(route('admin.logout'))
        ->assertRedirect(route('admin.login'));

    expect(auth()->guard('admin')->check())->toBeFalse();
});

it('opens the console once enrolment is confirmed', function () {
    $this->actingAs($this->unenrolled, 'admin')
        ->post(route('admin.two-factor.store'))
        ->assertRedirect(route('admin.two-factor.edit'));

    $secret = $this->unenrolled->fresh()->two_factor_secret;

    $this->actingAs($this->unenrolled, 'admin')
        ->post(route('admin.two-factor.confirm'), [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])
        ->assertRedirect(route('admin.two-factor.edit'));

    $this->actingAs($this->unenrolled->fresh(), 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk();
});

it('lets an enrolled staff member straight through', function () {
    $enrolled = AdminUser::factory()->create();
    $enrolled->assignRole('super-admin');

    $this->actingAs($enrolled, 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk();
});

/*
 * Impersonation is started from the console, so a staff member cannot be
 * inside a customer account without having passed this. They CAN lose their
 * enrolment while in there - disabling it is one click - and the way back out
 * must not be the one door this middleware closes.
 */
it('never blocks the way out of a customer account', function () {
    $this->actingAs($this->unenrolled, 'admin')
        ->post(route('admin.impersonation.stop'))
        ->assertRedirect();
});
