<?php

use App\Http\Controllers\Auth\RegisterController;
use App\Models\Plan;
use App\Models\User;
use App\Service\Billing\SubscriptionService;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

/*
 * Section 11 carries a chosen plan through signup; section 5 Path A puts "name
 * your workspace" between that choice and the card form. The step was
 * invisible - the plan sat in the session while the dashboard said only "You
 * are not in a workspace yet", so the plan somebody picked on the marketing
 * site quietly went nowhere.
 *
 * Section 15 calls trial-to-paid "the number this whole build exists to move".
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->user = User::factory()->create();
});

it('says nothing when no plan was carried through', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pending_plan', null));
});

it('names the plan and the trial that is still waiting', function () {
    $plan = Plan::query()->public()->where('is_free', false)->first();

    $this->actingAs($this->user)
        ->withSession([RegisterController::PENDING_PLAN => $plan->code])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pending_plan.name', $plan->name)
            ->where('pending_plan.trial_days', SubscriptionService::TRIAL_DAYS));
});

/*
 * The choice is spent by WorkspaceController::startPendingCheckout when the
 * workspace is actually created. Rendering the dashboard must not consume it,
 * or looking at the page would throw away the plan it just advertised.
 */
it('does not consume the choice by rendering the page', function () {
    $plan = Plan::query()->public()->where('is_free', false)->first();

    $this->actingAs($this->user)
        ->withSession([RegisterController::PENDING_PLAN => $plan->code])
        ->get(route('dashboard'))
        ->assertOk();

    expect(session()->get(RegisterController::PENDING_PLAN))->toBe($plan->code);
});

/*
 * Section 12: one trial per person, ever. Somebody who has spent theirs can
 * still buy, so the prompt stays - it just stops promising a trial it cannot
 * give, which TrialAlreadyConsumed would refuse a moment later anyway.
 */
it('stops promising a trial to someone who has already used theirs', function () {
    $plan = Plan::query()->public()->where('is_free', false)->first();
    $this->user->update(['trial_consumed_at' => now()]);

    $this->actingAs($this->user)
        ->withSession([RegisterController::PENDING_PLAN => $plan->code])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pending_plan.name', $plan->name)
            ->where('pending_plan.trial_days', null));
});

/*
 * The same narrowing a signup link gets. A code from a stale marketing page is
 * dropped rather than advertised - promising a plan that cannot be bought is
 * worse than saying nothing.
 */
it('ignores a plan that is no longer on sale', function (string $code) {
    $this->actingAs($this->user)
        ->withSession([RegisterController::PENDING_PLAN => $code])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pending_plan', null));
})->with(['no-such-plan', '']);

it('ignores the free floor plan, which is never sold', function () {
    $free = Plan::query()->where('is_free', true)->first();

    expect($free)->not->toBeNull();

    $this->actingAs($this->user)
        ->withSession([RegisterController::PENDING_PLAN => $free->code])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pending_plan', null));
});
