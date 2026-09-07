<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Models\PlanPrice;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * A page prop silently overrides a shared prop of the same name, and the layout
 * then reads a shape that is not there. It broke twice - first as `workspace`,
 * then as `workspaces` - so every page the customer shell renders is checked
 * here for the context the sidebar and banners depend on.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

it('keeps the tenancy context intact on every customer page', function (string $route) {
    $url = str_contains($route, '{workspace}')
        ? str_replace('{workspace}', $this->workspace->ulid, $route)
        : $route;

    $this->actingAs($this->owner)->get($url)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // the exact shape AppLayout and WorkspaceSwitcher read
            ->has('tenancy.current')
            ->has('tenancy.available')
            ->where('tenancy.current.name', 'Acme Inc'));
})->with([
    '/dashboard',
    '/billing',
    '/workspaces/{workspace}/members',
    '/workspaces/{workspace}/settings',
    '/settings/profile',
    '/settings/password',
]);

// Pages legitimately pass their own `workspace` prop; that must not be the
// same key the shell depends on.
it('lets a page keep its own workspace prop alongside the shared context', function () {
    $this->actingAs($this->owner)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspace.name', 'Acme Inc')
            ->where('tenancy.current.name', 'Acme Inc')
            ->has('tenancy.available', 1));
});

it('still carries the context on a workspace that is over its limit', function () {
    App\Models\WorkspaceMember::factory()->for($this->workspace)->count(3)->create();
    app(App\Contract\Workspace\MembershipContract::class)->syncSeats($this->workspace);

    $this->actingAs($this->owner)->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.current.state', 'over_limit')
            ->has('tenancy.available', 1));
});

it('carries the context while trialing, for the countdown banner', function () {
    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))->first();
    app(SubscriptionContract::class)->startTrial($this->workspace, $price, $this->owner);

    $this->actingAs($this->owner)->get('/billing')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.current.state', 'trialing')
            ->whereNot('tenancy.current.trial_ends_at', null));
});
