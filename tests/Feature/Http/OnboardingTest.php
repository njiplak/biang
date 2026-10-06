<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Http\Controllers\Auth\RegisterController;
use App\Models\PlanPrice;
use App\Models\ProductEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * One customer, one workspace, created on the way in instead of on a separate
 * "name your workspace" screen.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->user = User::factory()->create(['name' => 'Budi Santoso']);
});

it('creates the workspace named after the person', function () {
    $this->actingAs($this->user)
        ->get(route('onboarding'))
        ->assertRedirect(route('dashboard'));

    $workspace = $this->user->fresh()->workspaces()->firstOrFail();

    expect($workspace->name)->toBe("Budi's workspace")
        ->and(ProductEvent::where('name', 'workspace_created')->count())->toBe(1);
});

it('uses the name given at signup', function () {
    $this->actingAs($this->user)
        ->withSession([RegisterController::PENDING_WORKSPACE_NAME => 'Acme Inc'])
        ->get(route('onboarding'));

    expect($this->user->fresh()->workspaces()->firstOrFail()->name)->toBe('Acme Inc');
});

// Path A: the plan picked on the marketing site goes straight to the card form.
it('opens the card form for a plan carried through signup', function () {
    $gateway = fakeGateway();
    PlanPrice::query()->update(['dodo_product_id' => 'prod_x']);

    $this->actingAs($this->user)
        ->withSession([RegisterController::PENDING_PLAN => 'pro'])
        ->get(route('onboarding'))
        ->assertRedirect($gateway->checkoutUrl);

    expect($gateway->checkouts)->toHaveCount(1);
});

// Path C: an invitee joins someone else's workspace and gets no stray one.
it('sends an invitee to their invitation instead', function () {
    $owner = User::factory()->create();
    $workspace = app(WorkspaceContract::class)->create($owner, 'Host Co');
    WorkspaceInvitation::factory()->for($workspace)->create([
        'email' => $this->user->email,
        'token_hash' => hash('sha256', 'tok_123'),
    ]);

    $this->actingAs($this->user)
        ->withSession([RegisterController::PENDING_INVITATION => 'tok_123'])
        ->get(route('onboarding'))
        ->assertRedirect(route('invitation.show', 'tok_123'));

    expect($this->user->fresh()->workspaces()->count())->toBe(0);
});

it('leaves someone who already has a workspace alone', function () {
    app(WorkspaceContract::class)->create($this->user, 'Acme Inc');

    $this->actingAs($this->user)
        ->get(route('onboarding'))
        ->assertRedirect(route('dashboard'));

    expect(Workspace::count())->toBe(1);
});

// A member of someone else's workspace still counts as "in a workspace".
it('does not onboard a member of another workspace', function () {
    $workspace = app(WorkspaceContract::class)->create(User::factory()->create(), 'Host Co');
    WorkspaceMember::factory()->for($workspace)->for($this->user)->create();

    $this->actingAs($this->user)->get(route('dashboard'))->assertOk();
});

// No bouncing between the two when creation fails (here: no catalogue seeded).
it('lands on the dashboard rather than looping when it cannot create one', function () {
    // No floor plan: WorkspaceService::create throws NoFloorPlanConfigured.
    App\Models\Plan::query()->where('is_free', true)->update(['is_free' => false]);

    $this->actingAs($this->user)
        ->get(route('onboarding'))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($this->user)
        ->withSession([App\Http\Controllers\Workspace\OnboardingController::FAILED => true])
        ->get(route('dashboard'))
        ->assertOk();
});

// A closed workspace can be restored, so it still counts: the dashboard offers
// the restore instead of making a second one.
it('offers the restore instead of a new workspace', function () {
    $workspace = app(WorkspaceContract::class)->create($this->user, 'Old Co');
    app(WorkspaceContract::class)->closeWorkspace($workspace);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('closed_workspaces', 1));

    expect($this->user->fresh()->canCreateWorkspace())->toBeFalse();
});

it('refuses a second workspace', function () {
    app(WorkspaceContract::class)->create($this->user, 'Acme Inc');

    $this->actingAs($this->user)
        ->post(route('workspace.store'), ['name' => 'Second Co'])
        ->assertSessionHasErrors('errors');

    expect(Workspace::where('name', 'Second Co')->exists())->toBeFalse();
});
