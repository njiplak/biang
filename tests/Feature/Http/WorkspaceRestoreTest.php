<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 6: a closed workspace is recoverable until its purge date. The close
 * screen has always said so; this is the part that lets it be true.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->service = app(WorkspaceContract::class);
    $this->owner = User::factory()->create();
    $this->workspace = $this->service->create($this->owner, 'Acme Inc');

    $this->member = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($this->member)->create();

    $this->service->closeWorkspace($this->workspace);
});

it('lists a closed workspace on the owner dashboard', function () {
    $this->actingAs($this->owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('closed_workspaces', 1)
            ->where('closed_workspaces.0.ulid', $this->workspace->ulid));
});

// A button that could only ever answer 403 is worse than no button.
it('does not list it to someone who cannot restore it', function () {
    $this->actingAs($this->member)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('closed_workspaces', 0));
});

it('lets the owner restore it and switches them into it', function () {
    $this->actingAs($this->owner)
        ->post(route('workspace.restore', $this->workspace->ulid))
        ->assertRedirect(route('billing.index'));

    $fresh = Workspace::find($this->workspace->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->purge_after)->toBeNull()
        ->and($this->owner->fresh()->current_workspace_id)->toBe($this->workspace->id);
});

it('refuses a member who is not an owner', function () {
    $this->actingAs($this->member)
        ->post(route('workspace.restore', $this->workspace->ulid))
        ->assertForbidden();

    expect(Workspace::find($this->workspace->id))->toBeNull();
});

it('refuses once the retention window has passed', function () {
    Workspace::withTrashed()->whereKey($this->workspace->id)
        ->update(['purge_after' => now()->subMinute()]);

    $this->actingAs($this->owner)
        ->post(route('workspace.restore', $this->workspace->ulid))
        ->assertForbidden();
});

it('refuses once it has been anonymised', function () {
    Workspace::withTrashed()->whereKey($this->workspace->id)
        ->update(['anonymized_at' => now(), 'purge_after' => now()->addDay()]);

    $this->actingAs($this->owner)
        ->post(route('workspace.restore', $this->workspace->ulid))
        ->assertForbidden();
});
