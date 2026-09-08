<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingStatus;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
    $this->proPrice = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->first();

    // Published, as a price staff have put on sale would be - the gateway
    // refuses one without a product behind it, trial or purchase alike.
    $this->proPrice->update(['dodo_product_id' => 'prod_pro_month']);
});

// Section 5: "Billing lives on one page inside the app: current plan, usage
// against every limit, and buttons to change plan or buy add-ons."
it('shows the billing page to someone who may manage billing', function () {
    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/index')
            ->where('workspace.state', 'expired')
            ->where('subscription', null)
            // Section 5: usage against EVERY limit the plan carries. Plans ship
            // only limits something actually meters, so the free plan is seats
            // alone - a decorative limit here would be a fiction on the page.
            ->has('usage', 1)
            ->where('usage.0.feature', 'seats')
            ->has('plans'));
});

// Section 3: admins explicitly cannot see or touch billing.
it('hides billing from an admin', function () {
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();

    $this->actingAs($admin)->get(route('billing.index'))->assertForbidden();
});

it('lets a billing manager see billing', function () {
    $billing = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($billing)->billingManager()->create();

    $this->actingAs($billing)->get(route('billing.index'))->assertOk();
});

/*
 * Section 4: "A card is required to start. We collect it up front through
 * Dodo." So starting a trial hands the customer to the same checkout as a
 * purchase, with the first fourteen days free - and, exactly like a purchase,
 * nothing about our own state moves until their webhook says the card was
 * accepted. A customer who closes the card form has no trial and has not spent
 * their one.
 */
it('sends someone starting a trial to the card form', function () {
    $gateway = fakeGateway();

    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => $this->proPrice->id])
        ->assertRedirect($gateway->checkoutUrl);

    expect($gateway->checkouts)->toHaveCount(1)
        ->and($gateway->checkouts[0]['trial_period_days'])->toBe(14)
        ->and($gateway->checkouts[0]['plan_price_id'])->toBe($this->proPrice->id);

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid)
        ->and($this->owner->fresh()->hasConsumedTrial())->toBeFalse();
});

// Section 12: one trial per person, ever - surfaced as a message, not a crash.
it('explains why a second trial is refused', function () {
    $gateway = fakeGateway();
    $this->owner->update(['trial_consumed_at' => now()]);

    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => $this->proPrice->id])
        ->assertSessionHasErrors('errors');

    // Refused BEFORE the card form, not after. Being asked for a card and then
    // told you were never eligible is the worst order to do this in.
    expect($gateway->checkouts)->toBeEmpty()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);
});

it('changes plan', function () {
    app(SubscriptionContract::class)->grantPlan(
        $this->workspace,
        PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))->first(),
        AdminUser::factory()->create(),
        'seed',
    );

    $this->actingAs($this->owner)
        ->put(route('billing.plan'), ['plan_price_id' => $this->proPrice->id])
        ->assertRedirect();

    expect($this->workspace->fresh()->subscription->plan->code)->toBe('pro');
});

// Section 7: the downgrade is blocked and we say exactly how many to remove.
it('blocks a downgrade that would not fit and says so', function () {
    app(SubscriptionContract::class)->grantPlan($this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed');
    WorkspaceMember::factory()->for($this->workspace)->count(7)->create();

    $starter = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))->first();

    $response = $this->actingAs($this->owner)
        ->put(route('billing.plan'), ['plan_price_id' => $starter->id]);

    $response->assertSessionHasErrors('errors');
    expect(session('errors')->first('errors'))->toContain('Remove 3 before choosing it')
        ->and($this->workspace->fresh()->subscription->plan->code)->toBe('pro');
});

// Section 6: cancelling drops to free and deletes nothing.
it('cancels to the free tier', function () {
    app(SubscriptionContract::class)->grantPlan($this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed');

    $this->actingAs($this->owner)
        ->delete(route('billing.cancel'))
        ->assertRedirect();

    $fresh = $this->workspace->fresh();
    expect($fresh->billing_status)->toBe(BillingStatus::Unpaid)
        ->and($fresh->canRead())->toBeTrue();
});

it('stops a member cancelling the plan', function () {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($member)->create();

    $this->actingAs($member)->delete(route('billing.cancel'))->assertForbidden();
});

it('rejects a plan price that does not exist', function () {
    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => 999999])
        ->assertSessionHasErrors('plan_price_id');
});

// Section 12: free never touches the provider, so it has no price to buy.
it('rejects an archived price', function () {
    $archived = PlanPrice::factory()->for(Plan::firstWhere('code', 'pro'))->archived()->create();

    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => $archived->id])
        ->assertSessionHasErrors('plan_price_id');
});

/*
 * Section 5: "buttons to change plan, BUY ADD-ONS". The server has always sent
 * this payload; until the page rendered it, the only way to buy a seat was the
 * offer inside the invite flow, and there was no way at all to change a
 * quantity or drop one.
 */
it('offers the add-ons the current plan sells', function () {
    $this->seed(Database\Seeders\AddonSeeder::class);

    app(SubscriptionContract::class)->grantPlan(
        $this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed'
    );

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('addons.available', 1)
            ->where('addons.available.0.key', 'extra-seat')
            ->where('addons.available.0.kind', 'quantity')
            ->has('addons.owned', 0));
});

it('moves a bought add-on from the shelf to the bill', function () {
    $this->seed(Database\Seeders\AddonSeeder::class);

    app(SubscriptionContract::class)->grantPlan(
        $this->workspace, $this->proPrice, AdminUser::factory()->create(), 'seed'
    );

    $price = App\Models\AddonPrice::whereHas('addon', fn ($q) => $q->where('key', 'extra-seat'))->firstOrFail();

    $this->actingAs($this->owner)
        ->post(route('billing.addon.store'), ['addon_price_id' => $price->id, 'quantity' => 2])
        ->assertRedirect();

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('addons.owned', 1)
            ->where('addons.owned.0.quantity', 2)
            // no longer on offer: it is already theirs
            ->has('addons.available', 0));
});

// Section 12: free never touches the provider, so there is nothing to attach a
// paid add-on to and the page must not pretend otherwise.
it('offers no add-ons on the free tier', function () {
    $this->seed(Database\Seeders\AddonSeeder::class);

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('addons.available', 0)
            ->has('addons.owned', 0));
});

/*
 * Section 12: "Trial eligibility: one per person, ever." Sent so the page can
 * offer the way to BUY instead - a trial button that can only ever answer
 * "you have already used yours" is worse than no button.
 */
it('stops offering a trial once the person has spent theirs', function () {
    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can_start_trial', true));

    $this->owner->update(['trial_consumed_at' => now()]);

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can_start_trial', false));
});
