<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    // These tests cover the controller contract, not the asset pipeline.
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
});

it('requires a login to create a workspace', function () {
    $this->post(route('workspace.store'), ['name' => 'Acme'])
        ->assertRedirect('/auth/login');
});

it('creates a workspace with the creator as owner', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('workspace.store'), ['name' => 'Acme Inc'])
        ->assertRedirect();

    $workspace = Workspace::firstWhere('name', 'Acme Inc');

    expect($user->fresh()->roleIn($workspace))->toBe(WorkspaceRole::Owner)
        ->and($workspace->seatsUsed())->toBe(1);
});

it('rejects a workspace with no name', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('workspace.store'), ['name' => ''])
        ->assertSessionHasErrors('name');
});

it('lets a member switch to a workspace they belong to', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->create();

    $this->actingAs($user)
        ->post(route('workspace.switch', $workspace))
        ->assertRedirect();

    expect($user->fresh()->current_workspace_id)->toBe($workspace->id);
});

// Switching is the one place a user names a workspace directly, so it has to
// refuse one they are not in.
it('refuses to switch to a workspace the user does not belong to', function () {
    $user = User::factory()->create();
    $theirs = Workspace::factory()->create();

    $this->actingAs($user)
        ->post(route('workspace.switch', $theirs))
        ->assertForbidden();

    expect($user->fresh()->current_workspace_id)->toBeNull();
});

it('lets an admin rename the workspace', function () {
    $user = User::factory()->create();
    // Paying: renaming is a write, and a workspace nobody pays for is read-only.
    $workspace = Workspace::factory()->paying()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->admin()->create();

    $this->actingAs($user)
        ->put(route('workspace.update', $workspace), ['name' => 'Renamed'])
        ->assertRedirect();

    expect($workspace->fresh()->name)->toBe('Renamed');
});

it('stops a viewer renaming the workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->viewer()->create();

    $this->actingAs($user)
        ->put(route('workspace.update', $workspace), ['name' => 'Renamed'])
        ->assertForbidden();
});

// Section 7's hard block reaching HTTP: an over-limit workspace is read-only.
it('blocks writes to an over limit workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->overLimit()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->owner()->create();

    $this->actingAs($user)
        ->put(route('workspace.update', $workspace), ['name' => 'Renamed'])
        ->assertForbidden();
});

it('transfers ownership and demotes the previous owner', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($owner)->owner()->create();
    $successor = WorkspaceMember::factory()->for($workspace)->create();

    $this->actingAs($owner)
        ->post(route('workspace.transfer', $workspace), ['member_id' => $successor->id])
        ->assertRedirect();

    expect($successor->fresh()->role)->toBe(WorkspaceRole::Owner)
        ->and($owner->fresh()->roleIn($workspace))->toBe(WorkspaceRole::Admin);
});

it('stops an admin transferring ownership', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($user)->admin()->create();
    $target = WorkspaceMember::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->post(route('workspace.transfer', $workspace), ['member_id' => $target->id])
        ->assertForbidden();
});

it('lists members with their roles and seat usage', function () {
    $user = User::factory()->create();
    // built through the service, so entitlements are resolved the way they are
    // in production - a factory-made workspace has no seat allowance at all
    $workspace = app(WorkspaceContract::class)->create($user, 'Acme Inc');
    subscribeWorkspace($workspace);
    capSeats($workspace, 2);
    WorkspaceMember::factory()->for($workspace)->count(2)->create();

    $this->actingAs($user)
        ->get(route('workspace.member.index', $workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('workspace/members/index')
            // rows are not props: NextTable pulls them from the /fetch feed
            ->missing('members')
            ->where('seats.used', 3)
            ->where('seats.limit', 2)
            ->has('invitations'));
});

// The NextTable feed. Shape must match Base<T[]> or the table renders nothing.
it('feeds members as a paginated payload', function () {
    $user = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create($user, 'Acme Inc');
    WorkspaceMember::factory()->for($workspace)->count(2)->create();

    $response = $this->actingAs($user)
        ->getJson(route('workspace.member.fetch', $workspace))
        ->assertOk()
        ->assertJsonStructure(['items', 'current_page', 'total_page', 'per_page'])
        ->assertJsonCount(3, 'items');

    // the eager-loaded user is what the name and email columns read
    expect($response->json('items.0.user.email'))->not->toBeNull();
});

it('refuses the member feed to someone outside the workspace', function () {
    $workspace = app(WorkspaceContract::class)->create(User::factory()->create(), 'Acme Inc');

    $this->actingAs(User::factory()->create())
        ->getJson(route('workspace.member.fetch', $workspace))
        ->assertForbidden();
});
