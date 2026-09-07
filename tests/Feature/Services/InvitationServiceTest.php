<?php

use App\Contract\Workspace\InvitationContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Exceptions\Domain\InvitationNotAcceptable;
use App\Exceptions\Domain\SeatLimitReached;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Support\Features;

beforeEach(function () {
    $this->service = app(InvitationContract::class);
    Feature::factory()->create(['key' => Features::SEATS]);
    $plan = Plan::factory()->free()->create();
    $plan->features()->attach(Feature::where('key', Features::SEATS)->first(), ['value' => 3]);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

it('creates a pending invitation with a hashed token', function () {
    $invitation = $this->service->invite($this->workspace, 'new@example.com', WorkspaceRole::Member, $this->owner);

    expect($invitation->isPending())->toBeTrue()
        ->and($invitation->role)->toBe(WorkspaceRole::Member)
        ->and($invitation->token_hash)->not->toBeEmpty()
        ->and($invitation->token_hash)->not->toBe($invitation->plainToken);
});

// Section 7: "A seat limit should be a sales moment, not a wall" - so the
// failure has to name the add-on that fixes it, not just refuse.
it('refuses an invitation that would exceed the seat limit', function () {
    $this->service->invite($this->workspace, 'a@example.com', WorkspaceRole::Member, $this->owner);
    $this->service->invite($this->workspace, 'b@example.com', WorkspaceRole::Member, $this->owner);

    expect(fn () => $this->service->invite($this->workspace, 'c@example.com', WorkspaceRole::Member, $this->owner))
        ->toThrow(SeatLimitReached::class);
});

// The reason pending invitations reserve a seat at all.
it('counts pending invitations toward the limit before anyone accepts', function () {
    $this->service->invite($this->workspace, 'a@example.com', WorkspaceRole::Member, $this->owner);
    $this->service->invite($this->workspace, 'b@example.com', WorkspaceRole::Member, $this->owner);

    expect($this->workspace->fresh()->seatsUsed())->toBe(3);
});

it('frees the seat again when an invitation is revoked', function () {
    $invitation = $this->service->invite($this->workspace, 'a@example.com', WorkspaceRole::Member, $this->owner);
    $this->service->invite($this->workspace, 'b@example.com', WorkspaceRole::Member, $this->owner);

    $this->service->revoke($invitation, $this->owner);

    expect($this->workspace->fresh()->seatsUsed())->toBe(2)
        ->and(fn () => $this->service->invite($this->workspace, 'c@example.com', WorkspaceRole::Member, $this->owner))
        ->not->toThrow(SeatLimitReached::class);
});

// Section 5 Path C: accepting joins an EXISTING workspace - no new workspace,
// no trial, no card.
it('turns an accepted invitation into a membership', function () {
    $invitation = $this->service->invite($this->workspace, 'new@example.com', WorkspaceRole::Admin, $this->owner);
    $joiner = User::factory()->create(['email' => 'new@example.com']);

    $membership = $this->service->accept($invitation->plainToken, $joiner);

    expect($membership->role)->toBe(WorkspaceRole::Admin)
        ->and($joiner->fresh()->roleIn($this->workspace))->toBe(WorkspaceRole::Admin)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitation->fresh()->accepted_by_user_id)->toBe($joiner->id);
});

it('does not consume the joiner trial eligibility', function () {
    $invitation = $this->service->invite($this->workspace, 'new@example.com', WorkspaceRole::Member, $this->owner);
    $joiner = User::factory()->create(['email' => 'new@example.com']);

    $this->service->accept($invitation->plainToken, $joiner);

    expect($joiner->fresh()->hasConsumedTrial())->toBeFalse();
});

it('keeps the seat count correct after acceptance', function () {
    $invitation = $this->service->invite($this->workspace, 'new@example.com', WorkspaceRole::Member, $this->owner);
    $this->service->accept($invitation->plainToken, User::factory()->create(['email' => 'new@example.com']));

    expect($this->workspace->fresh()->seatsUsed())->toBe(2);
});

it('rejects an unknown token', function () {
    expect(fn () => $this->service->accept('not-a-real-token', User::factory()->create()))
        ->toThrow(InvitationNotAcceptable::class);
});

it('rejects an expired invitation', function () {
    $invitation = $this->service->invite($this->workspace, 'new@example.com', WorkspaceRole::Member, $this->owner);
    $token = $invitation->plainToken;
    $invitation->update(['expires_at' => now()->subDay()]);

    expect(fn () => $this->service->accept($token, User::factory()->create()))
        ->toThrow(InvitationNotAcceptable::class);
});

it('rejects a revoked invitation', function () {
    $invitation = $this->service->invite($this->workspace, 'new@example.com', WorkspaceRole::Member, $this->owner);
    $token = $invitation->plainToken;
    $this->service->revoke($invitation, $this->owner);

    expect(fn () => $this->service->accept($token, User::factory()->create()))
        ->toThrow(InvitationNotAcceptable::class);
});

it('rejects a token that was already used', function () {
    $invitation = $this->service->invite($this->workspace, 'new@example.com', WorkspaceRole::Member, $this->owner);
    $token = $invitation->plainToken;
    $this->service->accept($token, User::factory()->create(['email' => 'new@example.com']));

    expect(fn () => $this->service->accept($token, User::factory()->create()))
        ->toThrow(InvitationNotAcceptable::class);
});

it('refuses to add someone who is already a member', function () {
    $existing = WorkspaceMember::factory()->for($this->workspace)->create();
    $invitation = $this->service->invite($this->workspace, 'dupe@example.com', WorkspaceRole::Member, $this->owner);

    expect(fn () => $this->service->accept($invitation->plainToken, $existing->user))
        ->toThrow(InvitationNotAcceptable::class);
});

it('bumps the send count when resent', function () {
    $invitation = $this->service->invite($this->workspace, 'new@example.com', WorkspaceRole::Member, $this->owner);
    $originalHash = $invitation->token_hash;
    $originalToken = $invitation->plainToken;

    $resent = $this->service->resend($invitation);

    expect($resent->send_count)->toBe(2)
        ->and($resent->plainToken)->not->toBeEmpty()
        // a resend rotates the token, so the old link stops working
        ->and($resent->token_hash)->not->toBe($originalHash)
        ->and(WorkspaceInvitation::find($invitation->id)->token_hash)->not->toBe($originalHash);

    expect(fn () => $this->service->accept($originalToken, User::factory()->create()))
        ->toThrow(InvitationNotAcceptable::class);
});
