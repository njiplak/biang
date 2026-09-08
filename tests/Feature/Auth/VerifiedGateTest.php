<?php

use App\Contract\Workspace\InvitationContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

/*
 * Section 5, Paths A and B: "sign up → verify email → name your workspace".
 * The dashboard has always SAID so; until this gate existed nothing enforced
 * it, and an unverified account could create workspaces, invite colleagues and
 * start its one-per-person trial.
 *
 * What is deliberately NOT gated matters as much as what is - but the reason
 * changed. It used to be that changing an email cleared the proof, so a gate
 * could strand a legitimate owner mid-cleanup. AccountService now parks a new
 * address on `pending_email` instead, so an unverified account can only be a
 * signup that never verified. What stays open is now only what such an account
 * legitimately needs: the billing page it may have been sent to with a plan,
 * the exit, and the invitation that named its address.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->unverified = User::factory()->unverified()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->unverified, 'Acme Inc');

    $this->price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();
});

// Section 5: verification comes BEFORE naming a workspace.
it('will not let an unverified account create a workspace', function () {
    $this->actingAs($this->unverified)
        ->post(route('workspace.store'), ['name' => 'Second Co'])
        ->assertRedirect(route('verification.notice'));

    expect(Workspace::where('name', 'Second Co')->exists())->toBeFalse();
});

// Mail sent in a customer's name from an address nobody has proved they own.
it('will not let an unverified account invite anybody', function () {
    $this->actingAs($this->unverified)
        ->post(route('workspace.invitation.store', $this->workspace), [
            'email' => 'colleague@acme.test',
            'role' => WorkspaceRole::Member->value,
        ])
        ->assertRedirect(route('verification.notice'));

    expect(WorkspaceInvitation::count())->toBe(0);
});

/*
 * Section 12: "Trial eligibility: one per person, ever." A rule about a person
 * is worth nothing while the person is an unproved address - otherwise one
 * inbox is an unlimited supply of trials.
 */
it('will not let an unverified account start a trial', function () {
    $this->actingAs($this->unverified)
        ->post(route('billing.trial'), ['plan_price_id' => $this->price->id])
        ->assertRedirect(route('verification.notice'));

    expect($this->workspace->subscription()->withoutWorkspaceScope()->first())->toBeNull()
        ->and($this->unverified->fresh()->trial_consumed_at)->toBeNull();
});

it('will not let an unverified account reach checkout', function () {
    $this->actingAs($this->unverified)
        ->post(route('billing.checkout'), ['plan_price_id' => $this->price->id])
        ->assertRedirect(route('verification.notice'));
});

it('will not let an unverified account change plan', function () {
    $this->actingAs($this->unverified)
        ->put(route('billing.plan'), ['plan_price_id' => $this->price->id])
        ->assertRedirect(route('verification.notice'));
});

it('will not let an unverified account buy an add-on', function () {
    $this->actingAs($this->unverified)
        ->post(route('billing.addon.store'), ['addon_price_id' => 1])
        ->assertRedirect(route('verification.notice'));
});

// ------------------------------------------------- what stays open, and why

/*
 * Section 11 carries a chosen plan through signup and lands the customer here.
 * Bouncing them off the page they were sent to would lose the plan they picked
 * before they ever saw a price.
 */
it('still shows the billing page so a chosen plan is not lost', function () {
    $this->actingAs($this->unverified)
        ->get(route('billing.index'))
        ->assertOk();
});

// Never block the exit. Someone who cannot verify must still be able to leave.
it('still lets an unverified account cancel', function () {
    $this->actingAs($this->unverified)
        ->delete(route('billing.cancel'))
        ->assertRedirect();
});

/*
 * The lockout that used to force per-route gating, pinned so it cannot come
 * back: an owner who changes their email keeps every door open, because the
 * change is parked rather than applied. If AccountService ever goes back to
 * clearing email_verified_at, this fails.
 */
it('does not lock an owner out when they change their email', function () {
    $verified = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create($verified, 'Beta Co');

    $this->actingAs($verified)
        ->post(route('workspace.invitation.store', $workspace), [
            'email' => 'colleague@beta.test',
            'role' => WorkspaceRole::Member->value,
        ])->assertRedirect();

    $this->actingAs($verified)->patch(route('profile.update'), [
        'name' => $verified->name,
        'email' => 'moved@beta.test',
    ])->assertSessionHasNoErrors();

    expect($verified->fresh()->email_verified_at)->not->toBeNull()
        ->and($verified->fresh()->pending_email)->toBe('moved@beta.test');

    $pending = WorkspaceInvitation::where('email', 'colleague@beta.test')->firstOrFail();

    $this->actingAs($verified->fresh())
        ->delete(route('workspace.invitation.destroy', $pending))
        ->assertRedirect();

    expect($pending->fresh()->revoked_at)->not->toBeNull();
});

// The product itself is behind the gate: an account that never verified is held
// on the notice page rather than shown a dashboard it cannot use.
it('will not let an unverified account read a workspace or the dashboard', function () {
    $this->actingAs($this->unverified)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertRedirect(route('verification.notice'));

    $this->actingAs($this->unverified)
        ->post(route('workspace.switch', $this->workspace))
        ->assertRedirect(route('verification.notice'));

    $this->actingAs($this->unverified)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

/*
 * The one door left open to an unverified account, and the reason it is safe:
 * the workspace owner chose to invite this address, and joining spends nothing
 * and sends nothing in the joiner's name.
 */
it('still lets an unverified account accept an invitation', function () {
    $owner = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create($owner, 'Gamma Co');

    $invitation = app(InvitationContract::class)->invite(
        $workspace,
        $this->unverified->email,
        WorkspaceRole::Member,
        $owner,
    );

    $this->actingAs($this->unverified)
        ->post(route('invitation.accept', $invitation->plainToken))
        ->assertRedirect();

    expect($this->unverified->fresh()->belongsToWorkspace($workspace))->toBeTrue();
});

/*
 * The dead end this avoids: somebody mistypes their address at signup, so they
 * cannot reach the inbox to verify AND cannot reach the form to fix it. Without
 * these four exemptions a new account is the only way out.
 */
it('still lets an unverified signup correct a mistyped address', function () {
    $this->actingAs($this->unverified)
        ->get(route('profile.edit'))
        ->assertOk();

    $this->actingAs($this->unverified)
        ->patch(route('profile.update'), [
            'name' => $this->unverified->name,
            'email' => 'corrected@example.com',
        ])->assertSessionHasNoErrors();

    expect($this->unverified->fresh()->pending_email)->toBe('corrected@example.com');

    // And confirming it must work while still unverified, or the fix dead-ends.
    $this->actingAs($this->unverified)
        ->get(URL::temporarySignedRoute('settings.email.confirm', now()->addHour(), [
            'id' => $this->unverified->id,
            'hash' => sha1('corrected@example.com'),
        ]))->assertRedirect(route('profile.edit'));

    expect($this->unverified->fresh()->email)->toBe('corrected@example.com')
        ->and($this->unverified->fresh()->email_verified_at)->not->toBeNull();
});

// Never block the exit, and deleting the account is the ultimate exit.
it('still lets an unverified signup delete their account', function () {
    $solo = User::factory()->unverified()->create(['password' => Hash::make('password')]);

    $this->actingAs($solo)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect();

    expect(User::find($solo->id))->toBeNull();
});

// A verified account is untouched by any of this.
it('lets a verified account through every gate', function () {
    $verified = User::factory()->create();

    $this->actingAs($verified)
        ->post(route('workspace.store'), ['name' => 'Gamma Co'])
        ->assertRedirect();

    expect(Workspace::where('name', 'Gamma Co')->exists())->toBeTrue();
});

/*
 * Both pages used to carry a `must_verify_email` prop so a gated form could
 * explain itself. Neither can be reached by an account that would need it any
 * more, so the prop is gone rather than always false - a flag that is never
 * true is a thing the next person has to disprove.
 */
it('no longer ships a verification flag to pages behind the gate', function () {
    $verified = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create($verified, 'Delta Co');

    $this->actingAs($verified)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->missing('must_verify_email'));

    $this->actingAs($verified)
        ->get(route('workspace.member.index', $workspace))
        ->assertInertia(fn ($page) => $page->missing('must_verify_email'));
});
