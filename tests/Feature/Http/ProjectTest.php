<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Billing\UsageContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Exceptions\Domain\DowngradeBlocked;
use App\Models\PlanPrice;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\Features;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * The example product feature: tenant scoping, the write gate, and a metered
 * plan limit, working together the way a real feature should.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    // Starter: 10 projects.
    subscribeWorkspace($this->workspace, 'starter');
});

it('creates a project and counts it against the plan', function () {
    $this->actingAs($this->owner)
        ->post(route('project.store'), ['name' => 'Launch'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Project::withoutWorkspaceScope()->where('workspace_id', $this->workspace->id)->count())->toBe(1)
        ->and(app(UsageContract::class)->current($this->workspace, Features::PROJECTS))->toBe(1);
});

it('refuses a project past the plan limit and says so', function () {
    foreach (range(1, 10) as $i) {
        $this->actingAs($this->owner)->post(route('project.store'), ['name' => "P{$i}"]);
    }

    $this->actingAs($this->owner)
        ->post(route('project.store'), ['name' => 'One too many'])
        ->assertSessionHasErrors('errors');

    expect(Project::withoutWorkspaceScope()->count())->toBe(10);
});

it('lets a paid plan with no project limit have more', function () {
    $subscription = App\Models\Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->id)->live()->firstOrFail();
    subscriptions()->changePlan($this->workspace->fresh(), PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))->where('billing_interval', 'month')->firstOrFail());

    foreach (range(1, 11) as $i) {
        $this->actingAs($this->owner)->post(route('project.store'), ['name' => "P{$i}"]);
    }

    expect($subscription->fresh()->plan->code)->toBe('pro')
        ->and(Project::withoutWorkspaceScope()->count())->toBe(11);
});

// The write gate: a viewer's role, and a read-only workspace's state.
it('refuses a viewer', function () {
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($viewer)->viewer()->create();

    $this->actingAs($viewer)
        ->post(route('project.store'), ['name' => 'Nope'])
        ->assertForbidden();
});

it('refuses a read-only workspace', function () {
    subscriptions()->cancel($this->workspace->fresh());

    $this->actingAs($this->owner)
        ->post(route('project.store'), ['name' => 'Nope'])
        ->assertForbidden();
});

// Route binding runs before the tenant is set, so this is checked by hand.
it('will not touch a project from another workspace', function () {
    $other = User::factory()->create();
    $otherWorkspace = app(WorkspaceContract::class)->create($other, 'Other Co');
    subscribeWorkspace($otherWorkspace, 'starter');
    $project = app(App\Contract\Project\ProjectContract::class)->create($otherWorkspace, $other, 'Theirs', null);

    $this->actingAs($this->owner)
        ->delete(route('project.destroy', $project))
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->put(route('project.update', $project), ['name' => 'Mine now'])
        ->assertNotFound();

    expect($project->fresh()->name)->toBe('Theirs');
});

it('frees the slot when a project is deleted', function () {
    $this->actingAs($this->owner)->post(route('project.store'), ['name' => 'Launch']);
    $project = Project::withoutWorkspaceScope()->firstOrFail();

    $this->actingAs($this->owner)->delete(route('project.destroy', $project))->assertRedirect();

    expect(app(UsageContract::class)->current($this->workspace, Features::PROJECTS))->toBe(0);
});

// A plan that cannot hold the projects in use is refused before any charge.
it('blocks a downgrade that would not fit the projects in use', function () {
    subscriptions()->changePlan($this->workspace->fresh(), PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))->where('billing_interval', 'month')->firstOrFail());

    foreach (range(1, 12) as $i) {
        $this->actingAs($this->owner)->post(route('project.store'), ['name' => "P{$i}"]);
    }

    $starter = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))->where('billing_interval', 'month')->firstOrFail();

    expect(fn () => app(SubscriptionContract::class)->changePlan($this->workspace->fresh(), $starter))
        ->toThrow(DowngradeBlocked::class);
});

it('shows the page with usage against the limit', function () {
    $this->actingAs($this->owner)
        ->get(route('project.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('project/index')
            ->where('usage.limit', 10)
            ->where('can_write', true)
            ->where('tenancy.current.entitlements.projects', 10));
});
