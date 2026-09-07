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
