<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

it('shows settings with what this person may actually do', function () {
    $this->actingAs($this->owner)
        ->get(route('workspace.settings', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('workspace/settings')
            ->where('workspace.name', 'Acme Inc')
            ->where('can.rename', true)
            ->where('can.transfer', true)
            ->where('can.close', true)
            ->has('members'));
});

// Section 3: an admin manages people and settings but is not the owner.
it('hides ownership transfer and closing from an admin', function () {
    $admin = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();

    $this->actingAs($admin)
        ->get(route('workspace.settings', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.rename', true)
            ->where('can.transfer', false)
            ->where('can.close', false));
});

it('keeps a stranger out entirely', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('workspace.settings', $this->workspace))
        ->assertForbidden();
});

it('lets the owner close the workspace', function () {
    $this->actingAs($this->owner)
        ->delete(route('workspace.destroy', $this->workspace))
        ->assertRedirect(route('dashboard', absolute: false));

    expect(Workspace::find($this->workspace->id))->toBeNull()
        ->and(Workspace::withTrashed()->find($this->workspace->id)->purge_after)->not->toBeNull()
        ->and($this->owner->fresh()->current_workspace_id)->toBeNull();
});

it('refuses to let an admin close the workspace', function () {
    $admin = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();

    $this->actingAs($admin)
        ->delete(route('workspace.destroy', $this->workspace))
        ->assertForbidden();

    expect(Workspace::find($this->workspace->id))->not->toBeNull();
});

// Section 3: the last owner has to hand over first - leaving is not an escape.
it('stops the last owner leaving via the members endpoint', function () {
    $membership = $this->workspace->owners()->first();

    $this->actingAs($this->owner)
        ->delete(route('workspace.member.destroy', $membership))
        ->assertForbidden();
});
