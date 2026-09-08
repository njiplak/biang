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

    // There is no free tier, so a workspace nobody pays for is read-only. These
    // tests are about the shared props, not about being expired.
    subscribeWorkspace($this->workspace);
});

// Section 2: "the app always shows a workspace switcher", so the list has to be
// on every page, not just the dashboard.
it('shares the current workspace and the switcher list on every page', function () {
    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.current.name', 'Acme Inc')
            ->where('tenancy.current.can_write', true)
            ->where('tenancy.current.state', 'active')
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
    capSeats($this->workspace, 2);
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
    // Its own workspace: the shared setup already bought a plan for the other
    // one, and a workspace holds at most one live subscription.
    $trialing = app(WorkspaceContract::class)->create($this->owner, 'Trialing Ltd');
    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))->first();
    app(SubscriptionContract::class)->startTrial($trialing, $price, $this->owner);

    $this->owner->update(['current_workspace_id' => $trialing->id]);

    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.current.state', 'trialing')
            ->whereNot('tenancy.current.trial_ends_at', null)
            /*
             * Counted server-side. The banner used to work it out from
             * Date.now() during render, which is impure - the same component
             * can render twice and disagree with itself about the date.
             */
            ->where('tenancy.current.trial_days_left', 14));
});
