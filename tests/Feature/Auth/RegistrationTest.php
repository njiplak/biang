<?php

use App\Contract\Workspace\InvitationContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->withoutVite();
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

/*
 * Section 5: "sign up → verify email → name your workspace", in that order.
 * Signing up used to land on the dashboard with a banner asking nicely; the
 * order is now real, so there is nowhere to go but the notice page.
 */
test('new users can register and are sent to verify their email', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'test@example.com')->firstOrFail()->email_verified_at)->toBeNull();
});

test('a fresh signup cannot reach the dashboard', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

/*
 * Path C. We mailed a token to that inbox and they came back holding it, which
 * is the same proof the verification mail asks for - so asking again is asking
 * them to prove twice.
 */
test('registering through an invitation to the same address verifies it', function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $owner = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create($owner, 'Acme Inc');
    $invitation = app(InvitationContract::class)
        ->invite($workspace, 'invitee@example.com', WorkspaceRole::Member, $owner);

    $this->get(route('register', ['invitation' => $invitation->plainToken]))->assertOk();

    $this->post(route('register.store'), [
        'name' => 'Invitee',
        'email' => 'invitee@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('invitation.show', $invitation->plainToken));

    expect(User::where('email', 'invitee@example.com')->firstOrFail()->email_verified_at)->not->toBeNull();
});

/*
 * The hole this closes. If ANY held token verified whatever address its holder
 * typed, one invitation would be a machine for minting verified addresses - and
 * a verified address is what buys the right to send mail in our name.
 */
test('an invitation does not verify an address it was not sent to', function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $owner = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create($owner, 'Acme Inc');
    $invitation = app(InvitationContract::class)
        ->invite($workspace, 'invitee@example.com', WorkspaceRole::Member, $owner);

    $this->get(route('register', ['invitation' => $invitation->plainToken]))->assertOk();

    $this->post(route('register.store'), [
        'name' => 'Somebody Else',
        'email' => 'attacker@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'attacker@example.com')->firstOrFail()->email_verified_at)->toBeNull();
});

test('a stale or unknown invitation token is ignored rather than trusted', function () {
    $this->get(route('register', ['invitation' => 'not-a-real-token']))->assertOk();

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'test@example.com')->firstOrFail()->email_verified_at)->toBeNull();
});
