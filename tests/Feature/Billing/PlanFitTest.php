<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingStatus;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 7: "The downgrade is blocked until they remove three people. We tell
 * them exactly how many."
 *
 * That check used to run only when SWITCHING plans. Buying skipped it, so a
 * workspace with more people than a plan allows could pay for it and land
 * straight in the hard block - and with Dodo as merchant of record, undoing
 * that is a refund request rather than a rollback.
 *
 * It matters more now that there is no free tier: coming back from read-only is
 * a PURCHASE, and nobody was removed on the way down, so the workspace arrives
 * at the plan picker with everybody still on it.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    // Seven people against Starter's five.
    WorkspaceMember::factory()->for($this->workspace)->count(6)->create();

    $this->starter = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))
        ->where('billing_interval', 'month')->firstOrFail();
    $this->pro = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    $this->starter->update(['dodo_product_id' => 'prod_starter']);
    $this->pro->update(['dodo_product_id' => 'prod_pro']);
});

it('refuses to sell a plan that cannot hold the people already here', function () {
    $gateway = fakeGateway();

    $this->actingAs($this->owner)
        ->post(route('billing.checkout'), ['plan_price_id' => $this->starter->id])
        ->assertSessionHasErrors('errors');

    // The important half: nobody was sent to a card form.
    expect($gateway->checkouts)->toBeEmpty()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);
});

// Section 7: "we tell them exactly how many".
it('says exactly how many have to go', function () {
    fakeGateway();

    $this->actingAs($this->owner)
        ->post(route('billing.checkout'), ['plan_price_id' => $this->starter->id]);

    expect(session('errors')->first('errors'))
        ->toContain('Remove 2 before choosing it')
        ->toContain('allows 5')
        ->toContain('using 7');
});

// The same wall, on the trial that also takes a card.
it('refuses a trial on a plan that does not fit either', function () {
    $gateway = fakeGateway();

    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => $this->starter->id])
        ->assertSessionHasErrors('errors');

    expect($gateway->checkouts)->toBeEmpty();
});

it('sells a plan that does fit', function () {
    $gateway = fakeGateway();

    $this->actingAs($this->owner)
        ->post(route('billing.checkout'), ['plan_price_id' => $this->pro->id])
        ->assertRedirect('https://checkout.dodopayments.test/session/abc');

    expect($gateway->checkouts)->toHaveCount(1);
});

/*
 * A plan with no seat ceiling always fits. Null is unlimited everywhere in the
 * entitlement layer and must not read as zero here.
 */
it('treats an unlimited plan as always fitting', function () {
    $seats = App\Models\Feature::where('key', App\Support\Features::SEATS)->firstOrFail();
    $this->starter->plan->features()->updateExistingPivot($seats->id, ['value' => null]);

    expect(app(SubscriptionContract::class)
        ->seatOverageFor($this->workspace->fresh(), $this->starter))->toBe(0);
});

// The page has to say it before anything is clicked, not after the charge.
it('marks an unbuyable plan on the billing page', function () {
    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('plans.0.code', 'starter')
            ->where('plans.0.seat_overage', 2)
            ->where('plans.1.code', 'pro')
            ->where('plans.1.seat_overage', 0));
});

// Nothing about the switch path changed; it just shares the check now.
it('still blocks the same move as a downgrade', function () {
    app(SubscriptionContract::class)
        ->grantPlan($this->workspace, $this->pro, AdminUser::factory()->create(), 'seed');

    $this->actingAs($this->owner)
        ->put(route('billing.plan'), ['plan_price_id' => $this->starter->id])
        ->assertSessionHasErrors('errors');

    expect($this->workspace->fresh()->subscription->plan->code)->toBe('pro');
});

// And the current plan is marked, so nobody is offered what they already have.
it('marks the plan they are already on', function () {
    app(SubscriptionContract::class)
        ->grantPlan($this->workspace, $this->pro, AdminUser::factory()->create(), 'seed');

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('plans.1.code', 'pro')
            ->where('plans.1.is_current', true)
            ->where('plans.0.is_current', false));
});

// ----------------------------------------------------------------- invoices

/*
 * Section 8: "We keep a summary; the document itself stays with the provider."
 *
 * We kept the summary from the start and never showed it - the page had no
 * invoice list at all, so the only answer to "what was I charged" was Dodo's
 * own portal.
 */
it('shows the invoice summary we keep', function () {
    $subscription = app(SubscriptionContract::class)
        ->grantPlan($this->workspace, $this->pro, AdminUser::factory()->create(), 'seed');

    App\Models\InvoiceSummary::withoutWorkspaceScope()->create([
        'workspace_id' => $this->workspace->id,
        'subscription_id' => $subscription->id,
        'dodo_invoice_id' => 'pay_1',
        'number' => 'INV-001',
        'status' => 'paid',
        'currency' => 'USD',
        'subtotal_minor' => 4900,
        'tax_minor' => 490,
        'total_minor' => 5390,
        'issued_at' => now()->subDay(),
        'paid_at' => now()->subDay(),
    ]);

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invoices', 1)
            ->where('invoices.0.number', 'INV-001')
            // Every figure copied from them, never computed by us.
            ->where('invoices.0.total_minor', 5390)
            ->where('invoices.0.tax_minor', 490));
});

// A workspace that has never been charged has nothing to show, and that is a
// normal state rather than an empty table.
it('sends no invoices when there have been none', function () {
    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('invoices', 0));
});

/*
 * One workspace's money is never another's. The summary is workspace-scoped
 * and read with the scope lifted, so this is the test that keeps that honest.
 */
it('never shows another workspace invoices', function () {
    $other = app(WorkspaceContract::class)->create(User::factory()->create(), 'Beta Ltd');

    App\Models\InvoiceSummary::withoutWorkspaceScope()->create([
        'workspace_id' => $other->id,
        'dodo_invoice_id' => 'pay_other',
        'status' => 'paid',
        'currency' => 'USD',
        'subtotal_minor' => 100,
        'tax_minor' => 0,
        'total_minor' => 100,
        'issued_at' => now(),
    ]);

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('invoices', 0));
});
