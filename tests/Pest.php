<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Swaps in a Dodo that answers (or, with ->broken(), one that is down) and
 * hands it back so a test can assert on what it was asked to do.
 *
 * Global because every part of billing eventually talks to this seam, and the
 * alternative - an anonymous class per test file - meant every method added to
 * PaymentGatewayContract broke a handful of unrelated tests at once.
 */
function fakeGateway(): Tests\Fakes\FakePaymentGateway
{
    $fake = new Tests\Fakes\FakePaymentGateway;

    test()->swap(App\Contract\Billing\PaymentGatewayContract::class, $fake);

    return $fake;
}

/**
 * The subscription service, resolved fresh every call.
 *
 * Never cache this in a beforeEach: the payment gateway is constructor-injected,
 * so an instance built before fakeGateway() swaps the binding is holding the
 * real Dodo client and will try to reach the network.
 */
function subscriptions(): App\Contract\Billing\SubscriptionContract
{
    return app(App\Contract\Billing\SubscriptionContract::class);
}

/**
 * Give a workspace a live paid subscription.
 *
 * There is no free tier any more, so this is what buys the right to write
 * anything at all: a workspace nobody is paying for keeps its data and keeps it
 * readable, and that is the whole of it. Tests about members, invitations,
 * limits or settings therefore have to buy a plan first, exactly as a customer
 * now has to.
 *
 * Written directly rather than through SubscriptionService::grantPlan because
 * that needs an AdminUser and a reason, and most callers here are not testing
 * the staff-grant path - only standing in a workspace that can write.
 */
function subscribeWorkspace(
    App\Models\Workspace $workspace,
    App\Models\Plan|string $plan = 'pro',
): App\Models\Subscription {
    $plan = $plan instanceof App\Models\Plan
        ? $plan
        : App\Models\Plan::where('code', $plan)->firstOrFail();

    $price = $plan->prices()->whereNull('archived_at')->first()
        ?? App\Models\PlanPrice::factory()->for($plan)->create();

    $subscription = App\Models\Subscription::withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'plan_price_id' => $price->id,
        'status' => App\Enums\SubscriptionStatus::Active,
        'billing_source' => App\Enums\BillingSource::Manual,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $workspace->update(['billing_status' => App\Enums\BillingStatus::Active]);

    // The same two steps every settle() does, so entitlements and the seat
    // gauge match what the subscription now grants.
    app(App\Contract\Billing\EntitlementContract::class)->rebuild($workspace);
    app(App\Contract\Workspace\MembershipContract::class)->syncSeats($workspace);

    return $subscription;
}

/**
 * Set the seat allowance of the plan a workspace is on, then rebuild.
 *
 * The seeded floor plan used to grant two seats, which made "one more invite
 * hits the wall" free to set up. The floor grants unlimited now - it has to,
 * because an expired workspace cannot write anyway and a ceiling there would
 * only mislabel the reason - so a test about seat limits has to say which
 * ceiling it means.
 */
function capSeats(App\Models\Workspace $workspace, ?int $seats): void
{
    $subscription = App\Models\Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->live()
        ->firstOrFail();

    $feature = App\Models\Feature::where('key', App\Support\Features::SEATS)->firstOrFail();

    $subscription->plan->features()->updateExistingPivot($feature->id, ['value' => $seats]);

    app(App\Contract\Billing\EntitlementContract::class)->rebuild($workspace->fresh());
}
