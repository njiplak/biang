<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users with a workspace can visit the dashboard', function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $user = User::factory()->create();
    app(WorkspaceContract::class)->create($user, 'Acme Inc');

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

// One customer, one workspace, made for them on the way in.
test('someone with no workspace is sent to onboarding', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding'));
});
