<?php

use App\Contract\Auth\AccountContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Exceptions\Domain\LastOwnerCannotLeave;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->service = app(AccountContract::class);
});

it('updates the profile', function () {
    $user = User::factory()->create(['name' => 'Old', 'email' => 'old@example.com']);

    $this->service->updateProfile($user, ['name' => 'New', 'email' => 'new@example.com']);

    expect($user->fresh()->name)->toBe('New')
        ->and($user->fresh()->email)->toBe('new@example.com');
});

// Changing an email invalidates the proof that it belongs to you.
it('drops verification when the email actually changes', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    expect($user->email_verified_at)->not->toBeNull();

    $this->service->updateProfile($user, ['name' => $user->name, 'email' => 'new@example.com']);

    expect($user->fresh()->email_verified_at)->toBeNull();
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
