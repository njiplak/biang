<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingInterval;
use App\Enums\BillingSource;
use App\Enums\BillingStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\AddonNotAvailable;
use App\Exceptions\Domain\LifetimeNotCancellable;
use App\Exceptions\Domain\PlanChangeRequiresCheckout;
use App\Exceptions\Domain\PlanChangeUnavailable;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\Feature;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\Features;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

/*
 * A lifetime customer changes plan like anyone else - up, down, onto a
 * subscription - but there is no Dodo subscription behind a lifetime plan to
 * prorate against. So any move that costs money is a new checkout, and the
 * in-place change refuses it rather than handing the plan over for free.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    PlanPrice::all()->each(fn ($p) => $p->update(['dodo_product_id' => 'prod_'.$p->id]));

    $this->starter = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))
        ->where('billing_interval', 'month')->firstOrFail();
    $this->proMonthly = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    $this->starterLifetime = PlanPrice::factory()->for($this->starter->plan)->create([
        'billing_interval' => BillingInterval::Lifetime,
        'amount_minor' => 19_900,
        'dodo_product_id' => 'prod_starter_lt',
        'dodo_upgrade_product_id' => 'upgrade_starter_lt',
    ]);
    $this->proLifetime = PlanPrice::factory()->for($this->proMonthly->plan)->create([
        'billing_interval' => BillingInterval::Lifetime,
        'amount_minor' => 49_900,
        'dodo_product_id' => 'prod_pro_lt',
        'dodo_upgrade_product_id' => 'upgrade_pro_lt',
    ]);

    $this->holdLifetime = function (PlanPrice $price) {
        $subscription = Subscription::factory()->for($this->workspace)->create([
            'plan_id' => $price->plan_id,
            'plan_price_id' => $price->id,
            'status' => SubscriptionStatus::Active,
            'billing_source' => BillingSource::DodoOneTime,
            'dodo_subscription_id' => null,
            'dodo_payment_id' => 'pay_owned',
            'current_period_end' => null,
        ]);
        $this->workspace->update(['billing_status' => BillingStatus::Active]);
        app(App\Contract\Billing\EntitlementContract::class)->rebuild($this->workspace);

        return $subscription;
    };

    $this->holdMonthly = function (PlanPrice $price) {
        $subscription = Subscription::factory()->for($this->workspace)->create([
            'plan_id' => $price->plan_id,
            'plan_price_id' => $price->id,
            'dodo_subscription_id' => 'sub_monthly',
        ]);
        $this->workspace->update(['billing_status' => BillingStatus::Active]);
        app(App\Contract\Billing\EntitlementContract::class)->rebuild($this->workspace);

        return $subscription;
    };
});

// ------------------------------------------------ the in-place change refuses

it('refuses to move a lifetime plan up a tier without payment', function () {
    $gateway = fakeGateway();
    $lifetime = ($this->holdLifetime)($this->starterLifetime);

    expect(fn () => subscriptions()->changePlan($this->workspace->fresh(), $this->proLifetime))
        ->toThrow(PlanChangeRequiresCheckout::class);

    expect($lifetime->fresh()->plan_price_id)->toBe($this->starterLifetime->id)
        ->and($gateway->planChanges)->toBeEmpty();
});

it('refuses to move a lifetime plan onto a subscription in place', function () {
    fakeGateway();
    $lifetime = ($this->holdLifetime)($this->proLifetime);

    expect(fn () => subscriptions()->changePlan($this->workspace->fresh(), $this->starter))
        ->toThrow(PlanChangeRequiresCheckout::class);

    expect($lifetime->fresh()->plan_price_id)->toBe($this->proLifetime->id);
});

it('refuses to move a subscription onto lifetime in place', function () {
    $gateway = fakeGateway();
    $monthly = ($this->holdMonthly)($this->starter);

    expect(fn () => subscriptions()->changePlan($this->workspace->fresh(), $this->proLifetime))
        ->toThrow(PlanChangeRequiresCheckout::class);

    expect($monthly->fresh()->plan_price_id)->toBe($this->starter->id)
        ->and($gateway->planChanges)->toBeEmpty();
});

// Moving down costs nothing, so it happens now - lifetime is paid once and
// there is no renewal to wait for, and nothing is refunded.
it('moves a lifetime plan down a tier straight away', function () {
    $gateway = fakeGateway();
    $lifetime = ($this->holdLifetime)($this->proLifetime);

    subscriptions()->changePlan($this->workspace->fresh(), $this->starterLifetime);

    expect($lifetime->fresh()->plan_price_id)->toBe($this->starterLifetime->id)
        ->and($lifetime->fresh()->scheduled_plan_price_id)->toBeNull()
        ->and($gateway->planChanges)->toBeEmpty()
        ->and($gateway->checkouts)->toBeEmpty();
});

it('refuses a lifetime move into another currency', function () {
    fakeGateway();
    ($this->holdLifetime)($this->starterLifetime);

    $euro = PlanPrice::factory()->for($this->proMonthly->plan)->create([
        'billing_interval' => BillingInterval::Lifetime,
        'currency' => 'EUR',
        'amount_minor' => 1000,
        'dodo_product_id' => 'prod_eur_lt',
        'dodo_upgrade_product_id' => 'upgrade_eur_lt',
    ]);

    expect(fn () => subscriptions()->changePlan($this->workspace->fresh(), $euro))
        ->toThrow(PlanChangeUnavailable::class);
});

// --------------------------------------------- the billing page's one button

it('sends a lifetime upgrade to checkout for the difference only', function () {
    $gateway = fakeGateway();
    $lifetime = ($this->holdLifetime)($this->starterLifetime);

    $this->actingAs($this->owner)
        ->put(route('billing.plan'), ['plan_price_id' => $this->proLifetime->id])
        ->assertRedirect($gateway->checkoutUrl);

    expect($gateway->upgradeCheckouts)->toHaveCount(1)
        ->and($gateway->upgradeCheckouts[0]['product_id'])->toBe('upgrade_pro_lt')
        ->and($gateway->upgradeCheckouts[0]['amount_minor'])->toBe(30_000)
        ->and($gateway->upgradeCheckouts[0]['plan_price_id'])->toBe($this->proLifetime->id)
        ->and($gateway->upgradeCheckouts[0]['upgrade_from_plan_price_id'])->toBe($this->starterLifetime->id)
        // Nothing moves until the payment lands.
        ->and($lifetime->fresh()->plan_price_id)->toBe($this->starterLifetime->id);
});

it('sends a move onto lifetime to the lifetime checkout at full price', function () {
    $gateway = fakeGateway();
    $monthly = ($this->holdMonthly)($this->starter);

    $this->actingAs($this->owner)
        ->put(route('billing.plan'), ['plan_price_id' => $this->proLifetime->id])
        ->assertRedirect($gateway->checkoutUrl);

    expect($gateway->checkouts)->toHaveCount(1)
        ->and($gateway->checkouts[0]['plan_price_id'])->toBe($this->proLifetime->id)
        ->and($gateway->checkouts[0]['trial_period_days'])->toBeNull()
        ->and($gateway->cancellations)->toBeEmpty()
        ->and($monthly->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('sends a move off lifetime to a subscription checkout with no trial', function () {
    $gateway = fakeGateway();
    $lifetime = ($this->holdLifetime)($this->proLifetime);

    $this->actingAs($this->owner)
        ->put(route('billing.plan'), ['plan_price_id' => $this->starter->id])
        ->assertRedirect($gateway->checkoutUrl);

    expect($gateway->checkouts)->toHaveCount(1)
        ->and($gateway->checkouts[0]['plan_price_id'])->toBe($this->starter->id)
        ->and($gateway->checkouts[0]['trial_period_days'])->toBeNull()
        ->and($lifetime->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('still makes an ordinary subscription change in place', function () {
    $gateway = fakeGateway();
    ($this->holdMonthly)($this->starter);

    $this->actingAs($this->owner)
        ->put(route('billing.plan'), ['plan_price_id' => $this->proMonthly->id])
        ->assertRedirect();

    expect($gateway->planChanges)->toHaveCount(1)
        ->and($gateway->checkouts)->toBeEmpty();
});

// Section 7: the seat check refuses before anybody reaches a card form.
it('checks seats before sending anyone to a lifetime checkout', function () {
    $gateway = fakeGateway();
    ($this->holdLifetime)($this->proLifetime);
    WorkspaceMember::factory()->for($this->workspace)->count(6)->create();

    $this->actingAs($this->owner)
        ->put(route('billing.plan'), ['plan_price_id' => $this->starter->id])
        ->assertSessionHasErrors('errors');

    expect($gateway->checkouts)->toBeEmpty();
});

// ------------------------------------------------------- what it will cost

it('quotes the difference for a lifetime upgrade', function () {
    fakeGateway();
    ($this->holdLifetime)($this->starterLifetime);

    $this->actingAs($this->owner)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->proLifetime->id]))
        ->assertOk()
        ->assertJsonPath('preview', null)
        ->assertJsonPath('change.kind', 'lifetime_upgrade')
        ->assertJsonPath('change.amount_minor', 30_000)
        ->assertJsonPath('change.currency', 'USD');
});

it('warns that leaving lifetime gives it up', function () {
    fakeGateway();
    ($this->holdLifetime)($this->proLifetime);

    $this->actingAs($this->owner)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->starter->id]))
        ->assertOk()
        ->assertJsonPath('change.kind', 'leave_lifetime')
        ->assertJsonPath('change.amount_minor', $this->starter->amount_minor)
        ->assertJsonPath('change.interval', 'month');
});

it('quotes the full lifetime price with no credit when moving onto it', function () {
    fakeGateway();
    ($this->holdMonthly)($this->starter);

    $this->actingAs($this->owner)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->proLifetime->id]))
        ->assertOk()
        ->assertJsonPath('change.kind', 'buy_lifetime')
        ->assertJsonPath('change.amount_minor', 49_900);
});

it('says a lifetime downgrade costs nothing and refunds nothing', function () {
    fakeGateway();
    ($this->holdLifetime)($this->proLifetime);

    $this->actingAs($this->owner)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->starterLifetime->id]))
        ->assertOk()
        ->assertJsonPath('change.kind', 'lifetime_downgrade')
        ->assertJsonPath('change.amount_minor', 0);
});

// ------------------------------------------------------ what it never does

it('refuses to cancel a lifetime plan', function () {
    $gateway = fakeGateway();
    $lifetime = ($this->holdLifetime)($this->proLifetime);

    expect(fn () => subscriptions()->cancelAtPeriodEnd($this->workspace->fresh()))
        ->toThrow(LifetimeNotCancellable::class);

    expect($lifetime->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        ->and($gateway->cancellations)->toBeEmpty();
});

// Closing the workspace still ends it - that path does not ask.
it('still ends a lifetime plan when the workspace is closed', function () {
    fakeGateway();
    $lifetime = ($this->holdLifetime)($this->proLifetime);

    subscriptions()->cancel($this->workspace->fresh());

    expect($lifetime->fresh()->status)->toBe(SubscriptionStatus::Canceled);
});

it('refuses an add-on on a lifetime plan', function () {
    $gateway = fakeGateway();
    ($this->holdLifetime)($this->proLifetime);

    $addon = Addon::factory()->quantity()
        ->for(Feature::firstWhere('key', Features::SEATS))
        ->create(['grant_per_unit' => 1]);
    $price = AddonPrice::factory()->for($addon)->create();
    $this->proLifetime->plan->addons()->attach($addon);

    expect(fn () => subscriptions()->purchaseAddon($this->workspace->fresh(), $price))
        ->toThrow(AddonNotAvailable::class);

    expect($gateway->planChanges)->toBeEmpty();
});

it('refuses to start a second purchase while a lifetime plan is held', function () {
    $gateway = fakeGateway();
    ($this->holdLifetime)($this->starterLifetime);

    $this->actingAs($this->owner)
        ->post(route('billing.checkout'), ['plan_price_id' => $this->proLifetime->id])
        ->assertSessionHasErrors('errors');

    $this->actingAs($this->owner)
        ->post(route('billing.trial'), ['plan_price_id' => $this->starter->id])
        ->assertSessionHasErrors('errors');

    expect($gateway->checkouts)->toBeEmpty();
});

// --------------------------------------------------------------- the page

it('tells the billing page the plan is lifetime and offers no add-ons', function () {
    fakeGateway();
    ($this->holdLifetime)($this->proLifetime);

    $addon = Addon::factory()->quantity()->create();
    AddonPrice::factory()->for($addon)->create();
    $this->proLifetime->plan->addons()->attach($addon);

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('subscription.is_lifetime', true)
            ->where('subscription.interval', 'lifetime')
            ->where('subscription.current_period_end', null)
            ->where('subscription.paid_through', null)
            ->where('addons.available', []));
});

it('does not offer a seat add-on to a lifetime plan at its limit', function () {
    fakeGateway();
    ($this->holdLifetime)($this->starterLifetime);

    $addon = Addon::factory()->quantity()
        ->for(Feature::firstWhere('key', Features::SEATS))
        ->create(['grant_per_unit' => 1]);
    AddonPrice::factory()->for($addon)->create();
    $this->starterLifetime->plan->addons()->attach($addon);

    WorkspaceMember::factory()->for($this->workspace)->count(4)->create();
    app(App\Contract\Workspace\MembershipContract::class)->syncSeats($this->workspace);

    $this->actingAs($this->owner)
        ->get(route('workspace.member.index', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('seat_offer', null));
});
