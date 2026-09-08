<?php

use App\Contract\Billing\SubscriptionPullerContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingStatus;
use App\Enums\PullOutcome;
use App\Enums\SubscriptionStatus;
use App\Enums\WebhookEventSource;
use App\Models\InvoiceSummary;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 8 makes Dodo authoritative for money, and a webhook is only one way
 * of hearing them - the way that fails silently. A rejected signature, a lost
 * delivery and a reconcile that threw all leave our records saying exactly what
 * they say when nothing happened.
 *
 * So we ask. The answer goes through the SAME reconciler, which is what makes a
 * pulled subscription land in the state a delivered webhook would have left it.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->gateway = fakeGateway();

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    // What Dodo would say. Shaped as their webhook `data` block, because that
    // is what the real gateway translates their SDK objects into.
    $this->remote = function (array $overrides = []) {
        $this->gateway->remoteSubscriptions['sub_1'] = array_merge([
            'subscription_id' => 'sub_1',
            'status' => 'active',
            'metadata' => [
                'workspace_ulid' => $this->workspace->ulid,
                'plan_price_id' => (string) $this->price->id,
                'started_by_user_id' => (string) $this->owner->id,
            ],
            'customer' => ['customer_id' => 'cus_1'],
            'trial_period_days' => 0,
            'previous_billing_date' => now()->toIso8601String(),
            'next_billing_date' => now()->addMonth()->toIso8601String(),
            'cancel_at_next_billing_date' => false,
            'cancelled_at' => null,
        ], $overrides);
    };

    $this->paid = function (string $id = 'pay_1', int $amount = 4900) {
        $this->gateway->remotePayments[$id] = [
            'payment_id' => $id,
            'subscription_id' => 'sub_1',
            'status' => 'succeeded',
            'currency' => 'USD',
            'total_amount' => $amount,
            'settlement_amount' => $amount,
            'tax' => 0,
            'created_at' => now()->toIso8601String(),
            'customer' => ['customer_id' => 'cus_1'],
            'metadata' => [],
        ];
    };

    $this->pull = fn (?string $id = 'sub_1') => app(SubscriptionPullerContract::class)
        ->pull($id, $this->workspace);
});

// ------------------------------------------------------ the customer's return

/*
 * The whole point. Until this existed a customer who had just entered a card
 * landed on a page that said Free, and stayed there until a webhook arrived -
 * or forever, if one never did.
 */
it('settles a first purchase when the customer lands back from checkout', function () {
    ($this->remote)();
    ($this->paid)();

    $this->actingAs($this->owner)
        ->get(route('billing.index', ['subscription_id' => 'sub_1', 'status' => 'active']))
        ->assertRedirect(route('billing.index'));

    $subscription = $this->workspace->subscription()->withoutWorkspaceScope()->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        // Section 5's link out for cards and invoices needs this, and it used
        // to arrive only with the webhook.
        ->and($this->workspace->fresh()->dodo_customer_id)->toBe('cus_1');
});

/*
 * The redirect is what consumes the parameter. Without it a reload asks Dodo
 * again, and the back button asks a third time.
 */
it('drops the provider parameters so a reload does not ask again', function () {
    ($this->remote)();

    $this->actingAs($this->owner)
        ->get(route('billing.index', ['subscription_id' => 'sub_1', 'status' => 'active']))
        ->assertRedirect(route('billing.index'));

    expect($this->gateway->lookups)->not->toBeEmpty();

    $this->gateway->lookups = [];

    $this->actingAs($this->owner)->get(route('billing.index'))->assertOk();

    expect($this->gateway->lookups)->toBeEmpty();
});

// A plan carried from the marketing site has to survive being sent round again.
it('keeps a carried plan across the redirect', function () {
    ($this->remote)();

    $this->actingAs($this->owner)
        ->get(route('billing.index', ['subscription_id' => 'sub_1', 'plan' => 'pro']))
        ->assertRedirect(route('billing.index', ['plan' => 'pro']));
});

/*
 * The id comes out of a URL, so it is a hint to go and look - never evidence.
 * What binds the answer to this workspace is the ulid WE stamped into the
 * checkout metadata.
 */
it('refuses a subscription belonging to someone else', function () {
    $stranger = app(WorkspaceContract::class)->create(User::factory()->create(), 'Stranger Ltd');

    ($this->remote)(['metadata' => [
        'workspace_ulid' => $stranger->ulid,
        'plan_price_id' => (string) $this->price->id,
    ]]);

    $this->actingAs($this->owner)
        ->get(route('billing.index', ['subscription_id' => 'sub_1']))
        ->assertRedirect(route('billing.index'))
        ->assertSessionHas('warning');

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid)
        ->and($this->workspace->subscription()->withoutWorkspaceScope()->first())->toBeNull()
        // and nothing was written for the workspace that DOES own it either
        ->and($stranger->fresh()->billing_status)->toBe(BillingStatus::Unpaid);
});

// `status=active` is text anybody can type. Only the id is acted on, and only
// by asking Dodo about it.
it('ignores the status in the url entirely', function () {
    ($this->remote)(['status' => 'cancelled', 'cancelled_at' => now()->toIso8601String()]);

    Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->price->plan_id,
        'plan_price_id' => $this->price->id,
        'status' => SubscriptionStatus::Active,
        'dodo_subscription_id' => 'sub_1',
    ]);

    $this->actingAs($this->owner)
        ->get(route('billing.index', ['subscription_id' => 'sub_1', 'status' => 'active']));

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);
});

/*
 * This page renders from OUR records and has to keep working when Dodo does
 * not - section 8 makes ours authoritative for access precisely so it can.
 */
it('still renders the billing page when the provider cannot be reached', function () {
    $this->gateway->broken();

    $this->actingAs($this->owner)
        ->get(route('billing.index', ['subscription_id' => 'sub_1']))
        ->assertRedirect(route('billing.index'))
        ->assertSessionHas('warning');

    $this->actingAs($this->owner)->get(route('billing.index'))->assertOk();
});

// A refresh loop must not become a denial of service on our own provider quota.
it('stops asking the provider after too many returns', function () {
    ($this->remote)();

    for ($i = 0; $i < 12; $i++) {
        $this->actingAs($this->owner)
            ->get(route('billing.index', ['subscription_id' => 'sub_1']));
    }

    $subscriptionLookups = collect($this->gateway->lookups)
        ->filter(fn (array $call) => $call[0] === 'subscription')
        ->count();

    expect($subscriptionLookups)->toBe(10);
});

// ------------------------------------------------------------ what it records

/*
 * Section 4: a trial is a checkout with the first days free, and Dodo has no
 * `trialing` status - it is `active` with nothing charged. The discriminator is
 * whether money moved, so a pull that did not look at payments would call a
 * paying customer a trialist.
 */
it('records a subscription in its free days as trialing', function () {
    ($this->remote)([
        'trial_period_days' => 14,
        'next_billing_date' => now()->addDays(14)->toIso8601String(),
    ]);

    expect(($this->pull)())->toBe(PullOutcome::Applied);

    expect($this->workspace->subscription()->withoutWorkspaceScope()->first()->status)
        ->toBe(SubscriptionStatus::Trialing)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Trialing);
});

/*
 * The trap this ordering exists to avoid. Same payload as above, but the card
 * has been charged - and the only way to know is to have asked about payments
 * FIRST, because `hasBeenCharged` reads the invoice summaries this writes.
 */
it('records a charged subscription as paid even when it carries free days', function () {
    ($this->remote)([
        'trial_period_days' => 14,
        'next_billing_date' => now()->addMonth()->toIso8601String(),
    ]);
    ($this->paid)();

    ($this->pull)();

    expect($this->workspace->subscription()->withoutWorkspaceScope()->first()->status)
        ->toBe(SubscriptionStatus::Active)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active);
});

// Section 8: "We keep a summary; the document itself stays with the provider."
it('copies every figure of a payment from the provider', function () {
    ($this->remote)();
    $this->gateway->remotePayments['pay_1'] = [
        'payment_id' => 'pay_1',
        'subscription_id' => 'sub_1',
        'status' => 'succeeded',
        'currency' => 'USD',
        'total_amount' => 5390,
        'settlement_amount' => 4900,
        'tax' => 490,
        'created_at' => now()->toIso8601String(),
        'customer' => ['customer_id' => 'cus_1'],
        'metadata' => [],
    ];

    ($this->pull)();

    $invoice = InvoiceSummary::withoutWorkspaceScope()->where('dodo_invoice_id', 'pay_1')->firstOrFail();

    expect($invoice->total_minor)->toBe(5390)
        ->and($invoice->tax_minor)->toBe(490)
        ->and($invoice->subtotal_minor)->toBe(4900);
});

// A failed attempt is not an invoice, and counting one would end a trial that
// was never paid for.
it('never records a payment the provider did not settle', function () {
    ($this->remote)(['trial_period_days' => 14]);

    $this->gateway->remotePayments['pay_failed'] = [
        'payment_id' => 'pay_failed',
        'subscription_id' => 'sub_1',
        'status' => 'failed',
        'currency' => 'USD',
        'total_amount' => 4900,
        'created_at' => now()->toIso8601String(),
        'customer' => ['customer_id' => 'cus_1'],
        'metadata' => [],
    ];

    ($this->pull)();

    expect(InvoiceSummary::withoutWorkspaceScope()->count())->toBe(0)
        ->and($this->workspace->subscription()->withoutWorkspaceScope()->first()->status)
        ->toBe(SubscriptionStatus::Trialing);
});

/*
 * A pulled event carries no signature, because nothing signed it - we placed
 * the call. Recording it as `signature_verified` would put a lie in the audit
 * trail, so the source is what makes it evidence.
 */
it('records a pulled event as pulled rather than as a verified webhook', function () {
    ($this->remote)();

    ($this->pull)();

    $event = WebhookEvent::where('event_type', 'subscription.active')->firstOrFail();

    expect($event->source)->toBe(WebhookEventSource::Pull)
        ->and($event->signature_verified)->toBeFalse()
        ->and($event->isTrustworthy())->toBeTrue()
        ->and($event->processed_at)->not->toBeNull();
});

// An unsolicited event still has to prove itself.
it('still refuses an unsigned event that nobody asked for', function () {
    $event = WebhookEvent::create([
        'provider' => 'dodo',
        'source' => WebhookEventSource::Webhook,
        'event_id' => 'msg_forged',
        'event_type' => 'subscription.active',
        'payload' => ['type' => 'subscription.active', 'data' => []],
        'signature_verified' => false,
        'occurred_at' => now(),
        'received_at' => now(),
    ]);

    expect($event->isTrustworthy())->toBeFalse();
});

/*
 * The scheduled check runs every hour. Writing a row every hour for a
 * subscription that has not moved would turn the audit trail into noise, so the
 * event id is a fingerprint of the STATE, not of the moment of asking.
 */
it('records nothing the second time it sees the same state', function () {
    ($this->remote)();

    expect(($this->pull)())->toBe(PullOutcome::Applied);

    $after = WebhookEvent::count();

    expect(($this->pull)())->toBe(PullOutcome::InSync)
        ->and(WebhookEvent::count())->toBe($after);
});

// ...but a state that HAS moved is a different fingerprint, and is applied.
it('applies a state that has changed since the last look', function () {
    ($this->remote)();
    ($this->pull)();

    ($this->remote)(['status' => 'cancelled', 'cancelled_at' => now()->toIso8601String()]);

    expect(($this->pull)())->toBe(PullOutcome::Applied)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);
});

/*
 * A renewal moves the period without moving the status. Comparing status alone
 * would call a missed renewal "in step" and leave current_period_end pointing
 * at a date that has already passed.
 */
it('notices a renewal that only moved the billing date', function () {
    ($this->remote)();
    ($this->pull)();

    ($this->remote)(['next_billing_date' => now()->addMonths(2)->toIso8601String()]);

    expect(($this->pull)())->toBe(PullOutcome::Applied);

    expect($this->workspace->subscription()->withoutWorkspaceScope()->first()->current_period_end->toDateString())
        ->toBe(now()->addMonths(2)->toDateString());
});

// ------------------------------------------------------- the scheduled check

/*
 * Section 16's known risk, and the reason this runs on a schedule rather than
 * only on a page the customer might never load: "A customer cancels on the
 * provider's page. Our records find out via notification, not immediately."
 *
 * With a merchant of record, cancellation lives on their page as well as ours
 * (section 8), so the events most likely to go missing are the ones that should
 * REMOVE access.
 */
it('finds a cancellation the webhook never delivered', function () {
    Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->price->plan_id,
        'plan_price_id' => $this->price->id,
        'status' => SubscriptionStatus::Active,
        'dodo_subscription_id' => 'sub_1',
    ]);
    $this->workspace->update(['billing_status' => BillingStatus::Active]);

    ($this->remote)(['status' => 'cancelled', 'cancelled_at' => now()->toIso8601String()]);

    $this->artisan('billing:reconcile-subscriptions')
        ->expectsOutputToContain('Reconciled: 1')
        ->assertSuccessful();

    // `subscription()` is the LIVE one by design, and a cancelled subscription
    // is deliberately not live - so this reads the row itself.
    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid)
        ->and($this->workspace->subscriptions()->withoutWorkspaceScope()->first()->status)
        ->toBe(SubscriptionStatus::Canceled);
});

// A run that finds nothing wrong has to be distinguishable from one that did
// not look - empty monitoring is not proof of success.
it('reports a subscription that is already in step', function () {
    Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->price->plan_id,
        'plan_price_id' => $this->price->id,
        'status' => SubscriptionStatus::Active,
        'dodo_subscription_id' => 'sub_1',
        'current_period_end' => now()->addMonth(),
    ]);

    ($this->remote)();

    // The first run adopts what it sees; the second is the steady state this is
    // asserting, where an hourly check writes nothing at all.
    $this->artisan('billing:reconcile-subscriptions')->assertSuccessful();

    $events = WebhookEvent::count();

    $this->artisan('billing:reconcile-subscriptions')
        ->expectsOutputToContain('Already in step: 1')
        ->assertSuccessful();

    expect(WebhookEvent::count())->toBe($events);
});

/*
 * Section 14 phase 3: a comped subscription has no counterpart at Dodo, and
 * asking about one would be a guaranteed failure every hour, forever.
 */
it('never asks about a subscription granted by hand', function () {
    Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->price->plan_id,
        'plan_price_id' => $this->price->id,
        'status' => SubscriptionStatus::Active,
        'dodo_subscription_id' => null,
    ]);

    $this->artisan('billing:reconcile-subscriptions')
        ->expectsOutputToContain('Checked 0')
        ->assertSuccessful();

    expect($this->gateway->lookups)->toBeEmpty();
});

/*
 * One timeout must not end the run. A check that stops at the first unreachable
 * subscription leaves every subscription after it unchecked for another hour,
 * which is the failure this whole command exists to prevent.
 */
it('carries on past a subscription the provider will not answer about', function () {
    $second = app(WorkspaceContract::class)->create(User::factory()->create(), 'Beta Ltd');

    foreach ([[$this->workspace, 'sub_missing'], [$second, 'sub_1']] as [$workspace, $providerId]) {
        Subscription::factory()->for($workspace)->create([
            'plan_id' => $this->price->plan_id,
            'plan_price_id' => $this->price->id,
            'status' => SubscriptionStatus::Active,
            'dodo_subscription_id' => $providerId,
        ]);
    }

    // Only the second one is known to the provider; the first throws.
    $this->gateway->remoteSubscriptions['sub_1'] = [
        'subscription_id' => 'sub_1',
        'status' => 'cancelled',
        'metadata' => [
            'workspace_ulid' => $second->ulid,
            'plan_price_id' => (string) $this->price->id,
        ],
        'customer' => ['customer_id' => 'cus_2'],
        'trial_period_days' => 0,
        'previous_billing_date' => now()->toIso8601String(),
        'next_billing_date' => now()->addMonth()->toIso8601String(),
        'cancel_at_next_billing_date' => false,
        'cancelled_at' => now()->toIso8601String(),
    ];

    $this->artisan('billing:reconcile-subscriptions')
        ->expectsOutputToContain('Unreachable: 1')
        ->assertSuccessful();

    // The one after the failure was still checked, which is the whole point.
    expect($second->subscriptions()->withoutWorkspaceScope()->first()->status)
        ->toBe(SubscriptionStatus::Canceled);
});

// `?subscription_id[]=a` is an array, and stringifying one gives the literal
// "Array" - a warning in the log and a lookup for a subscription nobody has.
it('ignores a subscription id that is not a string', function () {
    ($this->remote)();

    $this->actingAs($this->owner)
        ->get(route('billing.index').'?subscription_id[]=sub_1')
        ->assertOk();

    expect($this->gateway->lookups)->toBeEmpty();
});
