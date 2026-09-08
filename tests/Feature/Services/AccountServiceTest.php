<?php

use App\Contract\Auth\AccountContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Exceptions\Domain\EmailAlreadyTaken;
use App\Exceptions\Domain\EmailChangeNotPending;
use App\Exceptions\Domain\LastOwnerCannotLeave;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\EmailChangeRequestedNotification;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->service = app(AccountContract::class);
});

it('updates the name immediately and parks the new email', function () {
    $user = User::factory()->create(['name' => 'Old', 'email' => 'old@example.com']);

    $this->service->updateProfile($user, ['name' => 'New', 'email' => 'new@example.com']);

    expect($user->fresh()->name)->toBe('New')
        ->and($user->fresh()->email)->toBe('old@example.com')
        ->and($user->fresh()->pending_email)->toBe('new@example.com');
});

/*
 * The reason pending_email exists. Clearing the proof here made an established
 * customer indistinguishable from a signup who had never verified, so once
 * verification became a gate a mistyped address locked them out of their own
 * account - workspaces, invitations and the cancel button included.
 */
it('keeps verification when the email change is only requested', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    expect($user->email_verified_at)->not->toBeNull();

    $this->service->updateProfile($user, ['name' => $user->name, 'email' => 'new@example.com']);

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->email)->toBe('old@example.com');
});

it('applies the new address only once it is confirmed', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->service->updateProfile($user, ['name' => $user->name, 'email' => 'new@example.com']);
    $this->service->confirmEmailChange($user, sha1('new@example.com'));

    expect($user->fresh()->email)->toBe('new@example.com')
        ->and($user->fresh()->pending_email)->toBeNull()
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

// A link issued for one address must not apply a different one they asked for
// later, or the first link becomes a way to take over any address they name.
it('refuses a confirmation whose hash is for a superseded address', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->service->updateProfile($user, ['name' => $user->name, 'email' => 'first@example.com']);
    $this->service->updateProfile($user, ['name' => $user->name, 'email' => 'second@example.com']);

    expect(fn () => $this->service->confirmEmailChange($user, sha1('first@example.com')))
        ->toThrow(EmailChangeNotPending::class);

    expect($user->fresh()->email)->toBe('old@example.com');
});

it('refuses a confirmation when nothing is pending', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    expect(fn () => $this->service->confirmEmailChange($user, sha1('anything@example.com')))
        ->toThrow(EmailChangeNotPending::class);
});

/*
 * pending_email carries no unique index on purpose, so two accounts can hold the
 * same address at once. Whoever confirms first takes it; the loser must get a
 * message, not a unique-constraint 500.
 */
it('refuses a confirmation for an address someone else took first', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->service->updateProfile($user, ['name' => $user->name, 'email' => 'contested@example.com']);

    User::factory()->create(['email' => 'contested@example.com']);

    expect(fn () => $this->service->confirmEmailChange($user, sha1('contested@example.com')))
        ->toThrow(EmailAlreadyTaken::class);

    expect($user->fresh()->email)->toBe('old@example.com')
        ->and($user->fresh()->pending_email)->toBe('contested@example.com');
});

it('cancels a pending change', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->service->updateProfile($user, ['name' => $user->name, 'email' => 'new@example.com']);
    $this->service->cancelEmailChange($user);

    expect($user->fresh()->pending_email)->toBeNull()
        ->and($user->fresh()->email)->toBe('old@example.com');
});

// Asking for the address they already have is how someone backs out of a
// pending change from the form itself.
it('clears a pending change when the current address is resubmitted', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->service->updateProfile($user, ['name' => $user->name, 'email' => 'new@example.com']);
    $this->service->updateProfile($user, ['name' => $user->name, 'email' => 'old@example.com']);

    expect($user->fresh()->pending_email)->toBeNull();
});

it('mails the new address to confirm and warns the old one', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->service->updateProfile($user, ['name' => $user->name, 'email' => 'new@example.com']);

    Notification::assertSentOnDemand(
        ConfirmEmailChangeNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@example.com'
    );

    Notification::assertSentTo($user, EmailChangeRequestedNotification::class);
});

it('does not re-send the confirmation when the same address is resubmitted', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->service->updateProfile($user, ['name' => 'One', 'email' => 'new@example.com']);
    $this->service->updateProfile($user, ['name' => 'Two', 'email' => 'new@example.com']);

    Notification::assertSentTimes(EmailChangeRequestedNotification::class, 1);
});

it('keeps verification when the email is unchanged', function () {
    $user = User::factory()->create();

    $this->service->updateProfile($user, ['name' => 'Renamed', 'email' => $user->email]);

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('changes the password', function () {
    $user = User::factory()->create();

    $this->service->changePassword($user, 'brand-new-password');

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue();
});

it('deletes an account with no workspaces', function () {
    $user = User::factory()->create();

    $this->service->deleteAccount($user);

    expect(User::find($user->id))->toBeNull();
});

// Section 3: a workspace must always have at least one owner. Deleting your
// account is just another way of leaving, so the same rule has to hold - or the
// cascade quietly orphans a paying workspace.
it('refuses to delete the sole owner of a workspace', function () {
    $user = User::factory()->create();
    app(WorkspaceContract::class)->create($user, 'Acme Inc');

    expect(fn () => $this->service->deleteAccount($user))
        ->toThrow(LastOwnerCannotLeave::class);

    expect(User::find($user->id))->not->toBeNull();
});

it('allows deletion once another owner exists', function () {
    $user = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create($user, 'Acme Inc');
    WorkspaceMember::factory()->for($workspace)->create(['role' => WorkspaceRole::Owner]);

    $this->service->deleteAccount($user);

    expect(User::find($user->id))->toBeNull()
        ->and(Workspace::find($workspace->id)->owners()->count())->toBe(1);
});

it('allows deletion when they are only a member elsewhere', function () {
    $user = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create(User::factory()->create(), 'Acme Inc');
    WorkspaceMember::factory()->for($workspace)->for($user)->create();

    $this->service->deleteAccount($user);

    expect(User::find($user->id))->toBeNull()
        ->and($workspace->fresh()->members()->count())->toBe(1);
});
