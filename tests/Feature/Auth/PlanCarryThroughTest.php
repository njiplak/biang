<?php

use App\Enums\BillingStatus;
use App\Http\Controllers\Auth\RegisterController;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\Workspace;
use App\Service\Billing\SubscriptionService;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 11: "Two buttons, two destinations. Start free goes to signup and
 * ends on the free tier. Start trial carries the chosen plan through signup."
 *
 * Two things have to survive the journey: the plan AND the billing interval.
 * The interval is chosen by the same pricing page toggle, and carrying only the
 * plan gave somebody who picked annual a monthly trial.
 *
 * Where the journey ENDS changed too. Section 4: "A card is required to start.
 * We collect it up front through Dodo." So naming the workspace hands the
 * customer to Dodo's checkout; it does not open a trial here, because a trial
 * with no card cannot auto-charge on day 15.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    // A price nobody published to Dodo has no product to sell, and the gateway
    // refuses it - so a test about reaching checkout has to publish first. One
    // id per row: the column is unique, because one price is one product.
    PlanPrice::query()->whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->get()
        ->each(fn (PlanPrice $price) => $price->update([
            'dodo_product_id' => 'prod_pro_'.$price->billing_interval->value,
        ]));
});

/*
 * Section 5: verification sits BETWEEN signing up and naming a workspace, in
 * both Path A and Path B. These tests are about the plan surviving that
 * journey, so they have to actually take the step the customer takes.
 */
function verifyLatestSignup(): void
{
    User::query()->latest('id')->firstOrFail()->markEmailAsVerified();

    // The guard resolved this user on the signup request and holds that
    // instance for the rest of the test process, so without this the next
    // request still sees the unverified copy. A real browser's next request
    // reloads them from the session, which is what this restores.
    app('auth')->forgetGuards();
}

/** Signs up and verifies, leaving the session in the state naming a workspace sees. */
function signUpWith(array $query = []): void
{
    test()->get(route('register', $query))->assertOk();

    test()->post(route('register.store'), [
        'name' => 'Jo',
        'email' => 'jo@example.com',
        'password' => 'Str0ng-password!',
        'password_confirmation' => 'Str0ng-password!',
    ])->assertRedirect();

    verifyLatestSignup();
}

it('carries a chosen plan from the signup link to the card form', function () {
    $gateway = fakeGateway();

    $this->get(route('register', ['plan' => 'pro']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/register')
            ->where('plan.code', 'pro')
            ->where('plan.name', 'Pro')
            // Section 4's fourteen days, quoted on the page that asks for the card.
            ->where('plan.trial_days', SubscriptionService::TRIAL_DAYS));

    signUpWith(['plan' => 'pro']);

    $this->post(route('workspace.store'), ['name' => 'Acme Inc'])
        ->assertRedirect('https://checkout.dodopayments.test/session/abc');

    $monthly = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    expect($gateway->checkouts)->toHaveCount(1)
        ->and($gateway->checkouts[0]['plan_price_id'])->toBe($monthly->id)
        // Section 4: the trial IS this checkout with the first days free.
        ->and($gateway->checkouts[0]['trial_period_days'])->toBe(SubscriptionService::TRIAL_DAYS);
});

/*
 * Section 8: nothing about our state moves until the webhook arrives. A
 * customer who reaches the card form and walks away must not be subscribed,
 * and must not have spent their one trial.
 */
it('moves nothing of ours by handing the customer to checkout', function () {
    fakeGateway();

    signUpWith(['plan' => 'pro']);

    $this->post(route('workspace.store'), ['name' => 'Acme Inc']);

    $workspace = Workspace::where('name', 'Acme Inc')->firstOrFail();

    expect($workspace->subscription()->withoutWorkspaceScope()->first())->toBeNull()
        ->and($workspace->billing_status)->toBe(BillingStatus::Unpaid)
        ->and(User::where('email', 'jo@example.com')->firstOrFail()->hasConsumedTrial())->toBeFalse();
});

// The toggle on the pricing page chooses an interval as much as a plan.
it('carries the chosen billing interval to the card form', function () {
    $gateway = fakeGateway();

    $this->get(route('register', ['plan' => 'pro', 'interval' => 'year']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('plan.interval', 'year')
            ->where('plan.amount_minor', 49_000));

    signUpWith(['plan' => 'pro', 'interval' => 'year']);

    $this->post(route('workspace.store'), ['name' => 'Acme Inc']);

    $annual = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'year')->firstOrFail();

    expect($gateway->checkouts[0]['plan_price_id'])->toBe($annual->id);
});

// No interval named is the ordinary case, and monthly is what the page quotes.
it('defaults to monthly when the link names no interval', function () {
    $this->get(route('register', ['plan' => 'pro']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('plan.interval', 'month')
            ->where('plan.amount_minor', 4900));
});

/*
 * An interval this plan does not sell falls back rather than dropping the plan,
 * and the RESOLVED interval is what is stored - so the card form charges the
 * price the signup page quoted.
 */
it('falls back to monthly when the plan does not sell the chosen interval', function () {
    $gateway = fakeGateway();

    PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'year')->update(['archived_at' => now()]);

    $this->get(route('register', ['plan' => 'pro', 'interval' => 'year']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('plan.interval', 'month'));

    signUpWith(['plan' => 'pro', 'interval' => 'year']);

    $this->post(route('workspace.store'), ['name' => 'Acme Inc']);

    $monthly = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    expect($gateway->checkouts[0]['plan_price_id'])->toBe($monthly->id);
});

it('ignores an interval that is not a billing interval at all', function () {
    $this->get(route('register', ['plan' => 'pro', 'interval' => 'fortnight']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('plan.interval', 'month'));
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

    verifyLatestSignup();

    $this->post(route('workspace.store'), ['name' => 'Free Co'])
        ->assertRedirect();

    $workspace = Workspace::where('name', 'Free Co')->firstOrFail();

    expect($workspace->subscription()->withoutWorkspaceScope()->first())->toBeNull()
        ->and($workspace->billing_status)->toBe(BillingStatus::Unpaid);
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
    // Free has no price to check out with, and needs no trial.
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
 * was refused would be a far worse outcome than landing on the free tier. They
 * are told before a card form rather than after entering one.
 */
it('still creates the workspace when the trial is refused', function () {
    $gateway = fakeGateway();
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
        ->and($workspace->billing_status)->toBe(BillingStatus::Unpaid)
        // Refused before the card form, not after.
        ->and($gateway->checkouts)->toBeEmpty();
});

/*
 * Section 14 phase 3 keeps the product sellable by hand with no provider wired
 * up at all, so a checkout that cannot be opened is a normal state. It must
 * never cost the customer the workspace they just named.
 */
it('still creates the workspace when the provider is down', function () {
    fakeGateway()->broken();

    signUpWith(['plan' => 'pro']);

    $this->post(route('workspace.store'), ['name' => 'Acme Inc'])
        ->assertRedirect()
        ->assertSessionHas('warning');

    $workspace = Workspace::where('name', 'Acme Inc')->firstOrFail();

    expect($workspace->billing_status)->toBe(BillingStatus::Unpaid);
});

it('still creates the workspace when the plan was never published to the provider', function () {
    fakeGateway();

    PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->update(['dodo_product_id' => null]);

    signUpWith(['plan' => 'pro']);

    $this->post(route('workspace.store'), ['name' => 'Acme Inc'])
        ->assertRedirect()
        ->assertSessionHas('warning');

    expect(Workspace::where('name', 'Acme Inc')->exists())->toBeTrue();
});

// One workspace per customer: a second is refused, and the plan was spent on the first.
it('refuses a second workspace and checks out only the first', function () {
    $gateway = fakeGateway();

    signUpWith(['plan' => 'pro']);

    $this->post(route('workspace.store'), ['name' => 'First Co']);
    $this->post(route('workspace.store'), ['name' => 'Second Co'])
        ->assertSessionHasErrors('errors');

    expect(Workspace::where('name', 'Second Co')->exists())->toBeFalse()
        ->and($gateway->checkouts)->toHaveCount(1)
        ->and($gateway->checkouts[0]['workspace'])
        ->toBe(Workspace::where('name', 'First Co')->firstOrFail()->ulid);
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

/*
 * A message the customer never sees is the same as no message at all. Every
 * warning above is raised on a request that then redirects, so it has to
 * survive to the page that finally renders.
 */
it('shows the customer why the card form did not open', function () {
    fakeGateway()->broken();

    signUpWith(['plan' => 'pro']);

    $this->post(route('workspace.store'), ['name' => 'Acme Inc']);

    $workspace = Workspace::where('name', 'Acme Inc')->firstOrFail();

    $this->get(route('workspace.member.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('flash.warning', fn (?string $warning) => $warning !== null
                && str_contains($warning, 'not available right now')));
});
