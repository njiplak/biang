<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
});

// Section 3: deleting your account is just another way of leaving, so the
// last-owner rule has to hold here too - otherwise the cascade on
// workspace_members silently orphans a workspace nobody can administer.
it('refuses to delete the sole owner of a workspace and says why', function () {
    $user = User::factory()->create();
    app(WorkspaceContract::class)->create($user, 'Acme Inc');

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('errors');

    expect(User::find($user->id))->not->toBeNull();
    expect(session('errors')->first('errors'))->toContain('Transfer ownership');
});

it('allows deletion once ownership has been handed over', function () {
    $user = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create($user, 'Acme Inc');
    WorkspaceMember::factory()->for($workspace)->create(['role' => WorkspaceRole::Owner]);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    expect(User::find($user->id))->toBeNull();
});

// Regression: SessionGuard::logout() cycles the remember token, which saves the
// model - and saving a deleted model re-inserts it. Logout must come first.
it('stays deleted even though the account had a remember token', function () {
    $user = User::factory()->create(['remember_token' => 'a-real-token']);

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);

    expect(User::find($user->id))->toBeNull();
    $this->assertGuest();
});

/*
 * The exit is never blocked - routes/web/settings.php says so, and this used
 * to be the one case where it was. `impersonation_sessions.user_id` was
 * restrictOnDelete against a hard-deleted users table, so a customer who had
 * ever been helped by support got a 500 instead of a closed account: the
 * failure landed on exactly the people most likely to have contacted us.
 */
it('lets a customer who has been impersonated delete their account', function () {
    $user = App\Models\User::factory()->create();
    $staff = App\Models\AdminUser::factory()->create();

    $session = App\Models\ImpersonationSession::create([
        'ulid' => (string) Illuminate\Support\Str::ulid(),
        'admin_user_id' => $staff->id,
        'user_id' => $user->id,
        'reason' => 'support ticket 123',
        'started_at' => now(),
    ]);

    app(App\Contract\Auth\AccountContract::class)->deleteAccount($user);

    expect(App\Models\User::find($user->id))->toBeNull();

    /*
     * Section 10 wants "a permanent record that we did it". The record has to
     * outlive the customer - only the pointer to them goes, so nobody can
     * erase evidence of their own impersonation by closing their account.
     */
    $session->refresh();

    expect($session->exists)->toBeTrue()
        ->and($session->user_id)->toBeNull()
        ->and($session->admin_user_id)->toBe($staff->id)
        ->and($session->reason)->toBe('support ticket 123');
});
