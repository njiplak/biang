<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * The workspace banners send billing roles to /billing and tell everyone else
 * who can fix it. Both halves need this context on every page.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create(['name' => 'Budi']);
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

it('tells the banner an owner can open billing', function () {
    $this->actingAs($this->owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('tenancy.current.can_manage_billing', true)
            ->where('tenancy.current.owner_name', 'Budi'));
});

it('tells the banner a member cannot, and who to ask', function () {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($member)->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('tenancy.current.can_manage_billing', false)
            ->where('tenancy.current.owner_name', 'Budi'));
});
