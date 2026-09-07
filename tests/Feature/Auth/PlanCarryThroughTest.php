<?php

use App\Enums\BillingStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Auth\RegisterController;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 11: "Two buttons, two destinations. Start free goes to signup and
 * ends on the free tier. Start trial carries the chosen plan through signup."
 *
 * The RegisterController docblock claimed this from the start; the code did not
 * do it. The plan has to survive signup AND the separate workspace-naming step,
 * because a workspace does not exist until it is named and a trial belongs to a
 * workspace.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
});

it('carries a chosen plan from the signup link to a started trial', function () {
    $this->get(route('register', ['plan' => 'pro']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/register')
            ->where('plan.code', 'pro')
            ->where('plan.name', 'Pro'));

    $this->post(route('register.store'), [
        'name' => 'Jo',
        'email' => 'jo@example.com',
        'password' => 'Str0ng-password!',
        'password_confirmation' => 'Str0ng-password!',
    ])->assertRedirect();

    $this->post(route('workspace.store'), ['name' => 'Acme Inc'])->assertRedirect();

    $workspace = Workspace::where('name', 'Acme Inc')->firstOrFail();
    $subscription = $workspace->subscription()->withoutWorkspaceScope()->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->plan->code)->toBe('pro')
        ->and($workspace->billing_status)->toBe(BillingStatus::Trialing)
        ->and($subscription->trial_ends_at->isFuture())->toBeTrue();
});

// Section 5 Path A: "Start free" ends on the free tier with no card.
it('leaves a plain signup on the free tier', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('plan', null));

    $this->post(route('register.store'), [
        'name' => 'Sam',
        'email' => 'sam@example.com',
        'password' => 'Str0ng-password!',
        'password_confirmation' => 'Str0ng-password!',
    ]);

    $this->post(route('workspace.store'), ['name' => 'Free Co']);

    $workspace = Workspace::where('name', 'Free Co')->firstOrFail();

    expect($workspace->subscription()->withoutWorkspaceScope()->first())->toBeNull()
        ->and($workspace->billing_status)->toBe(BillingStatus::Free);
});

/*
 * The visitor followed a link from a different project. A stale or mistyped
 * code should give them an ordinary signup, not an error page.
 */
it('ignores a plan code that cannot be bought', function (string $code) {
    $this->get(route('register', ['plan' => $code]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('plan', null));

    expect(session(RegisterController::PENDING_PLAN))->toBeNull();
})->with([
    'unknown' => 'does-not-exist',
    // Free needs no trial, and startTrial would spend their one trial on it.
    'the free plan' => 'free',
]);

it('ignores a retired plan', function () {
    Plan::where('code', 'pro')->update(['archived_at' => now()]);

    $this->get(route('register', ['plan' => 'pro']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('plan', null));
});

/*
 * Section 12: one trial per person, ever. Somebody opening a second workspace
 * from a trial link must still get the workspace - losing it because the trial
 * was refused would be a far worse outcome than landing on the free tier.
 */
it('still creates the workspace when the trial is refused', function () {
    $user = User::factory()->create(['trial_consumed_at' => now()->subMonth()]);

    // Straight to the state signup leaves behind. Hitting /auth/register while
    // signed in is not that state - it is guest-only, so RedirectIfAuthenticated
    // sends them to the dashboard and no plan is ever carried.
    $this->actingAs($user)
        ->withSession([RegisterController::PENDING_PLAN => 'pro'])
        ->post(route('workspace.store'), ['name' => 'Second Co'])
        ->assertRedirect()
        ->assertSessionHas('warning');

    $workspace = Workspace::where('name', 'Second Co')->firstOrFail();

    expect($workspace->subscription()->withoutWorkspaceScope()->first())->toBeNull()
        ->and($workspace->billing_status)->toBe(BillingStatus::Free);
});

// The plan is spent on the workspace it was carried to, not every later one.
it('does not start a second trial on the next workspace', function () {
    $this->get(route('register', ['plan' => 'pro']));

    $this->post(route('register.store'), [
        'name' => 'Jo',
        'email' => 'jo@example.com',
        'password' => 'Str0ng-password!',
        'password_confirmation' => 'Str0ng-password!',
    ]);

    $this->post(route('workspace.store'), ['name' => 'First Co']);
    $this->post(route('workspace.store'), ['name' => 'Second Co']);

    $second = Workspace::where('name', 'Second Co')->firstOrFail();

    expect($second->subscription()->withoutWorkspaceScope()->first())->toBeNull();
});

/*
 * Section 11's "Start trial" button does not know whether the visitor is
 * signed in. The signup page is guest-only, so an authenticated one is bounced
 * - and the plan they picked used to be dropped in silence.
 */
it('carries the plan to billing when the visitor is already signed in', function () {
    $user = User::factory()->create();
    $workspace = app(\App\Contract\Workspace\WorkspaceContract::class)->create($user, 'Acme Inc');

    $this->actingAs($user)
        ->get(route('register', ['plan' => 'pro']))
        ->assertRedirect(route('billing.index', ['plan' => 'pro']));

    $this->actingAs($user)
        ->get(route('billing.index', ['plan' => 'pro']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('preselected_plan', 'pro'));

    // Preselected, not acted on: starting a trial stays their decision.
    expect($workspace->fresh()->subscription()->withoutWorkspaceScope()->first())->toBeNull();
});

it('still sends a plain signed-in visitor to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('register'))
        ->assertRedirect(route('dashboard', absolute: false));
});
