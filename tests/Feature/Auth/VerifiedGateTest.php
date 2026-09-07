<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 5, Paths A and B: "sign up → verify email → name your workspace".
 * The dashboard has always SAID so; until this gate existed nothing enforced
 * it, and an unverified account could create workspaces, invite colleagues and
 * start its one-per-person trial.
 *
 * What is deliberately NOT gated matters as much as what is. Changing an email
 * address clears the proof (AccountService::updateProfile), so every gate here
 * is something a legitimate owner loses until they verify again - which is why
 * reading, switching, revoking and cancelling stay open.
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
 * The lockout this gate must not create: a verified owner changes their email,
 * which clears the proof, and now has pending invitations they cannot tidy up.
 */
it('still lets them revoke an invitation after an email change', function () {
    $verified = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create($verified, 'Beta Co');

    $invitation = $this->actingAs($verified)
        ->post(route('workspace.invitation.store', $workspace), [
            'email' => 'colleague@beta.test',
            'role' => WorkspaceRole::Member->value,
        ]);

    $invitation->assertRedirect();
    expect(WorkspaceInvitation::where('email', 'colleague@beta.test')->exists())->toBeTrue();

    // Section 5's own flow: a changed address is not a proved one.
    $verified->update(['email_verified_at' => null]);

    $pending = WorkspaceInvitation::where('email', 'colleague@beta.test')->firstOrFail();

    $this->actingAs($verified)
        ->delete(route('workspace.invitation.destroy', $pending))
        ->assertRedirect();

    // Revoking marks the invitation, it does not delete it - the record of who
    // was invited and who withdrew it is the point.
    expect($pending->fresh()->revoked_at)->not->toBeNull();
});

it('still lets an unverified account switch workspace and read its members', function () {
    $this->actingAs($this->unverified)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertOk();

    $this->actingAs($this->unverified)
        ->post(route('workspace.switch', $this->workspace))
        ->assertRedirect();
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
 * A gate the page does not know about is a trap: the customer types out an
 * invitation, submits, and is bounced to the notice with no explanation. Both
 * screens that own a gated form are told, so they can say why.
 */
it('tells the members page that inviting is gated', function () {
    $this->actingAs($this->unverified)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertInertia(fn ($page) => $page->where('must_verify_email', true));

    $this->unverified->markEmailAsVerified();
    app('auth')->forgetGuards();

    $this->actingAs($this->unverified->fresh())
        ->get(route('workspace.member.index', $this->workspace))
        ->assertInertia(fn ($page) => $page->where('must_verify_email', false));
});

it('tells the dashboard that creating a workspace is gated', function () {
    $this->actingAs($this->unverified)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('must_verify_email', true));
});
