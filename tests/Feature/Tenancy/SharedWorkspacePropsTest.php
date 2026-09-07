<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\AdminRoleSeeder;
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

// Section 2: "the app always shows a workspace switcher", so the list has to be
// on every page, not just the dashboard.
it('shares the current workspace and the switcher list on every page', function () {
    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.current.name', 'Acme Inc')
            ->where('tenancy.current.can_write', true)
            ->where('tenancy.current.state', 'free')
            ->has('tenancy.available', 1));
});

it('lists every workspace the person belongs to, with their role in each', function () {
    $second = app(WorkspaceContract::class)->create(User::factory()->create(), 'Beta Ltd');
    WorkspaceMember::factory()->for($second)->for($this->owner)->viewer()->create();

    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tenancy.available', 2));
});

it('shares nothing for a guest', function () {
    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tenancy', null));
});

// The admin console is a different world; a staff session must not carry a
// customer workspace context.
it('shares no workspace context on the admin console', function () {
    $this->seed(AdminRoleSeeder::class);
    $admin = AdminUser::factory()->create();
    $admin->assignRole('super-admin');

    $this->actingAs($admin, 'admin')->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy', null)
            ->where('auth.user', null)
            ->where('auth.admin.email', $admin->email));
});

// Section 7: the banner has to name the specific limits.
it('surfaces the over limit state and which features broke', function () {
    WorkspaceMember::factory()->for($this->workspace)->count(3)->create();
    app(App\Contract\Workspace\MembershipContract::class)->syncSeats($this->workspace);

    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.current.state', 'over_limit')
            ->where('tenancy.current.can_write', false)
            ->where('tenancy.current.over_limit_features', ['seats']));
});

// Section 4 and 16: the trial auto-charges, so the app has to say when.
it('surfaces the trial deadline', function () {
    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))->first();
    app(SubscriptionContract::class)->startTrial($this->workspace, $price, $this->owner);

    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.current.state', 'trialing')
            ->whereNot('tenancy.current.trial_ends_at', null));
});
