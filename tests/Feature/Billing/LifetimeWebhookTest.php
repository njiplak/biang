<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingInterval;
use App\Enums\BillingSource;
use App\Enums\BillingStatus;
use App\Enums\SubscriptionStatus;
use App\Models\DunningState;
use App\Models\InvoiceSummary;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use StandardWebhooks\Webhook;

/*
 * A lifetime purchase is a one-time payment at Dodo: no subscription id, ever.
 * These are the webhooks that turn one into a plan, move it, and take it back.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->secret = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    config(['dodo.webhook_key' => $this->secret, 'dodo.grace_days' => 14]);

    $this->gateway = fakeGateway();

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

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

    $this->send = function (array $body) {
        $payload = json_encode($body);
        $id = 'msg_'.bin2hex(random_bytes(6));
        $timestamp = time();
        $signature = (new Webhook($this->secret))->sign($id, $timestamp, $payload);

        return $this->call('POST', route('webhook.dodo'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_ID' => $id,
            'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => $signature,
        ], $payload);
    };

    // A one-time payment as Dodo reports it: no subscription_id at all.
    $this->payment = fn (PlanPrice $price, array $data = [], array $metadata = []) => [
        'type' => 'payment.succeeded',
        'timestamp' => now()->toIso8601String(),
        'data' => array_merge([
            'payment_id' => 'pay_lt_1',
            'total_amount' => $price->amount_minor,
            'currency' => $price->currency,
            'customer' => ['customer_id' => 'cus_lt_1'],
            'metadata' => array_merge([
                'workspace_ulid' => $this->workspace->ulid,
                'plan_price_id' => (string) $price->id,
                'started_by_user_id' => (string) $this->owner->id,
            ], $metadata),
        ], $data),
    ];

    $this->live = fn () => Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->id)->live()->first();

    // A lifetime plan already held, as the reconciler would have left it.
    $this->holdLifetime = function (PlanPrice $price, string $paymentId = 'pay_owned') {
        $subscription = Subscription::factory()->for($this->workspace)->create([
            'plan_id' => $price->plan_id,
            'plan_price_id' => $price->id,
            'status' => SubscriptionStatus::Active,
            'billing_source' => BillingSource::DodoOneTime,
            'dodo_subscription_id' => null,
            'dodo_payment_id' => $paymentId,
            'current_period_end' => null,
        ]);
        $this->workspace->update(['billing_status' => BillingStatus::Active]);

        return $subscription;
    };
});

// ------------------------------------------------------------ buying it

it('opens a lifetime plan from a one-time payment', function () {
    ($this->send)(($this->payment)($this->proLifetime))->assertOk();

    $subscription = ($this->live)();

    expect($subscription)->not->toBeNull()
        ->and($subscription->plan_price_id)->toBe($this->proLifetime->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->billing_source)->toBe(BillingSource::DodoOneTime)
        ->and($subscription->dodo_payment_id)->toBe('pay_lt_1')
        ->and($subscription->dodo_subscription_id)->toBeNull()
        ->and($subscription->current_period_end)->toBeNull()
        ->and($subscription->isLifetime())->toBeTrue()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        ->and($this->workspace->fresh()->dodo_customer_id)->toBe('cus_lt_1')
        ->and(InvoiceSummary::withoutWorkspaceScope()->where('dodo_invoice_id', 'pay_lt_1')->value('subscription_id'))
        ->toBe($subscription->id);
});

// Two deliveries of the same payment under different event ids is still one sale.
it('opens one lifetime plan however often the payment is reported', function () {
    ($this->send)(($this->payment)($this->proLifetime))->assertOk();
    ($this->send)(($this->payment)($this->proLifetime))->assertOk();

    expect(Subscription::withoutWorkspaceScope()->where('workspace_id', $this->workspace->id)->count())->toBe(1)
        ->and(InvoiceSummary::withoutWorkspaceScope()->count())->toBe(1);
});

it('records a lifetime payment for a workspace that does not exist without inventing one', function () {
    ($this->send)(($this->payment)($this->proLifetime, metadata: ['workspace_ulid' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']))->assertOk();

    expect(Subscription::withoutWorkspaceScope()->count())->toBe(0)
        ->and(WebhookEvent::firstOrFail()->failed_at)->not->toBeNull();
});

/*
 * A declined attempt at the lifetime checkout is a payment.failed with no
 * subscription id. It used to fall through to the workspace's live
 * subscription and open a dunning episode on a card that is fine.
 */
it('does not put a healthy subscription into dunning when a lifetime attempt is declined', function () {
    $monthly = Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->proMonthly->plan_id,
        'plan_price_id' => $this->proMonthly->id,
        'dodo_subscription_id' => 'sub_monthly',
    ]);
    $this->workspace->update(['billing_status' => BillingStatus::Active]);

    $event = ($this->payment)($this->proLifetime);
    $event['type'] = 'payment.failed';
    ($this->send)($event)->assertOk();

    expect($monthly->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(DunningState::withoutWorkspaceScope()->count())->toBe(0)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active);
});

// ---------------------------------------------------- recurring -> lifetime

it('replaces a recurring subscription with the lifetime plan and stops the old charges', function () {
    $monthly = Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->proMonthly->plan_id,
        'plan_price_id' => $this->proMonthly->id,
        'dodo_subscription_id' => 'sub_monthly',
    ]);
    $this->workspace->update(['billing_status' => BillingStatus::Active]);

    ($this->send)(($this->payment)($this->proLifetime))->assertOk();

    $monthly->refresh();

    expect(($this->live)()->plan_price_id)->toBe($this->proLifetime->id)
        ->and($monthly->status)->toBe(SubscriptionStatus::Expired)
        ->and($monthly->ended_at)->not->toBeNull()
        // A switch is not churn: canceled_at is what the churn figures count.
        ->and($monthly->canceled_at)->toBeNull()
        ->and($this->gateway->cancellations)->toHaveCount(1)
        ->and($this->gateway->cancellations[0]['subscription'])->toBe('sub_monthly')
        ->and($this->gateway->cancellations[0]['at_period_end'])->toBeFalse();
});

/*
 * Cancelling the old subscription makes Dodo echo a `subscription.cancelled`
 * for it. That used to settle the workspace as unpaid - a customer who had
 * just paid for lifetime went read-only.
 */
it('keeps the lifetime plan when the replaced subscription reports its cancellation', function () {
    Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->proMonthly->plan_id,
        'plan_price_id' => $this->proMonthly->id,
        'dodo_subscription_id' => 'sub_monthly',
    ]);
    $this->workspace->update(['billing_status' => BillingStatus::Active]);

    ($this->send)(($this->payment)($this->proLifetime))->assertOk();

    ($this->send)([
        'type' => 'subscription.cancelled',
        'timestamp' => now()->addMinute()->toIso8601String(),
        'data' => ['subscription_id' => 'sub_monthly', 'status' => 'cancelled'],
    ])->assertOk();

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        ->and(($this->live)()->plan_price_id)->toBe($this->proLifetime->id);
});

// Dodo still charging a subscription we replaced is double billing: never
// revive it, and ask them to stop again.
it('never revives a replaced subscription that renews at the provider', function () {
    $monthly = Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->proMonthly->plan_id,
        'plan_price_id' => $this->proMonthly->id,
        'dodo_subscription_id' => 'sub_monthly',
    ]);
    $this->workspace->update(['billing_status' => BillingStatus::Active]);

    ($this->send)(($this->payment)($this->proLifetime))->assertOk();

    ($this->send)([
        'type' => 'subscription.renewed',
        'timestamp' => now()->addMinute()->toIso8601String(),
        'data' => [
            'subscription_id' => 'sub_monthly',
            'status' => 'active',
            'next_billing_date' => now()->addMonth()->toIso8601String(),
        ],
    ])->assertOk();

    expect($monthly->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and(($this->live)()->plan_price_id)->toBe($this->proLifetime->id)
        ->and($this->gateway->cancellations)->toHaveCount(2);
});

// A trial started without a card, or a plan granted by hand, has no provider
// record to stop.
it('replaces a plan with no provider record without calling the provider', function () {
    $manual = Subscription::factory()->for($this->workspace)->manual()->create([
        'plan_id' => $this->starter->plan_id,
        'plan_price_id' => $this->starter->id,
        'dodo_subscription_id' => null,
    ]);
    $this->workspace->update(['billing_status' => BillingStatus::Active]);

    ($this->send)(($this->payment)($this->proLifetime))->assertOk();

    expect($manual->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and(($this->live)()->plan_price_id)->toBe($this->proLifetime->id)
        ->and($this->gateway->cancellations)->toBeEmpty();
});

/*
 * Not lifetime-specific, but the same hole: a late cancellation for a
 * subscription that already ended must not take away the one that replaced it.
 */
it('ignores a late cancellation for a subscription that has already been replaced', function () {
    Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->starter->plan_id,
        'plan_price_id' => $this->starter->id,
        'status' => SubscriptionStatus::Canceled,
        'canceled_at' => now()->subDay(),
        'ended_at' => now()->subDay(),
        'dodo_subscription_id' => 'sub_old',
    ]);
    Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->proMonthly->plan_id,
        'plan_price_id' => $this->proMonthly->id,
        'dodo_subscription_id' => 'sub_new',
    ]);
    $this->workspace->update(['billing_status' => BillingStatus::Active]);

    ($this->send)([
        'type' => 'subscription.cancelled',
        'timestamp' => now()->toIso8601String(),
        'data' => ['subscription_id' => 'sub_old', 'status' => 'cancelled'],
    ])->assertOk();

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        ->and(($this->live)()->dodo_subscription_id)->toBe('sub_new');
});

// ---------------------------------------------------- lifetime -> recurring

it('ends the lifetime plan when a new subscription for the workspace starts', function () {
    $lifetime = ($this->holdLifetime)($this->starterLifetime);

    ($this->send)([
        'type' => 'subscription.active',
        'timestamp' => now()->toIso8601String(),
        'data' => [
            'subscription_id' => 'sub_after_lifetime',
            'metadata' => [
                'workspace_ulid' => $this->workspace->ulid,
                'plan_price_id' => (string) $this->proMonthly->id,
            ],
            'next_billing_date' => now()->addMonth()->toIso8601String(),
        ],
    ])->assertOk();

    $live = ($this->live)();

    expect($live->id)->not->toBe($lifetime->id)
        ->and($live->plan_price_id)->toBe($this->proMonthly->id)
        ->and($live->dodo_subscription_id)->toBe('sub_after_lifetime')
        ->and($live->status)->toBe(SubscriptionStatus::Active)
        ->and($lifetime->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($lifetime->fresh()->dodo_subscription_id)->toBeNull()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active);
});

// Ending a lifetime plan for a subscription we cannot place would leave the
// customer with neither.
it('keeps the lifetime plan when the new subscription names no price', function () {
    $lifetime = ($this->holdLifetime)($this->starterLifetime);

    ($this->send)([
        'type' => 'subscription.active',
        'timestamp' => now()->toIso8601String(),
        'data' => [
            'subscription_id' => 'sub_unplaceable',
            'metadata' => ['workspace_ulid' => $this->workspace->ulid],
        ],
    ])->assertOk();

    expect($lifetime->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(WebhookEvent::firstOrFail()->failed_at)->not->toBeNull();
});

// Only an event that establishes a subscription may end a lifetime plan.
it('does not apply another subscription\'s trouble to a lifetime plan', function () {
    $lifetime = ($this->holdLifetime)($this->starterLifetime);

    ($this->send)([
        'type' => 'subscription.on_hold',
        'timestamp' => now()->toIso8601String(),
        'data' => [
            'subscription_id' => 'sub_stranger',
            'metadata' => [
                'workspace_ulid' => $this->workspace->ulid,
                'plan_price_id' => (string) $this->proMonthly->id,
            ],
        ],
    ])->assertOk();

    expect($lifetime->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(DunningState::withoutWorkspaceScope()->count())->toBe(0)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active);
});

// ------------------------------------------------------ lifetime upgrades

it('moves a lifetime plan up a tier when the difference is paid', function () {
    $lifetime = ($this->holdLifetime)($this->starterLifetime);

    ($this->send)(($this->payment)(
        $this->proLifetime,
        ['payment_id' => 'pay_upgrade', 'total_amount' => 30_000],
        ['upgrade_from_plan_price_id' => (string) $this->starterLifetime->id],
    ))->assertOk();

    expect($lifetime->fresh()->plan_price_id)->toBe($this->proLifetime->id)
        ->and($lifetime->fresh()->plan_id)->toBe($this->proLifetime->plan_id)
        // The original purchase stays the one that owns the plan.
        ->and($lifetime->fresh()->dodo_payment_id)->toBe('pay_owned')
        ->and(InvoiceSummary::withoutWorkspaceScope()->where('dodo_invoice_id', 'pay_upgrade')->value('subscription_id'))
        ->toBe($lifetime->id)
        ->and(WebhookEvent::firstOrFail()->processed_at)->not->toBeNull();
});

it('refuses an upgrade that paid less than the difference', function () {
    $lifetime = ($this->holdLifetime)($this->starterLifetime);

    ($this->send)(($this->payment)(
        $this->proLifetime,
        ['payment_id' => 'pay_short', 'total_amount' => 100],
        ['upgrade_from_plan_price_id' => (string) $this->starterLifetime->id],
    ))->assertOk();

    $event = WebhookEvent::firstOrFail();

    expect($lifetime->fresh()->plan_price_id)->toBe($this->starterLifetime->id)
        ->and($event->failed_at)->not->toBeNull()
        ->and($event->error)->toContain('underpaid')
        // The money still happened, so the record of it is kept.
        ->and(InvoiceSummary::withoutWorkspaceScope()->where('dodo_invoice_id', 'pay_short')->exists())->toBeTrue();
});

it('refuses an upgrade paid in another currency', function () {
    $lifetime = ($this->holdLifetime)($this->starterLifetime);

    ($this->send)(($this->payment)(
        $this->proLifetime,
        ['payment_id' => 'pay_eur', 'total_amount' => 900_000, 'currency' => 'EUR'],
        ['upgrade_from_plan_price_id' => (string) $this->starterLifetime->id],
    ))->assertOk();

    expect($lifetime->fresh()->plan_price_id)->toBe($this->starterLifetime->id)
        ->and(WebhookEvent::firstOrFail()->error)->toContain('currency');
});

// The plan moved between checkout and payment: the price paid for no longer
// describes the step being taken.
it('refuses an upgrade from a plan the workspace is no longer on', function () {
    $lifetime = ($this->holdLifetime)($this->proLifetime);

    ($this->send)(($this->payment)(
        $this->proLifetime,
        ['payment_id' => 'pay_stale'],
        ['upgrade_from_plan_price_id' => (string) $this->starterLifetime->id],
    ))->assertOk();

    expect($lifetime->fresh()->plan_price_id)->toBe($this->proLifetime->id)
        ->and(WebhookEvent::firstOrFail()->error)->toContain('no longer matches');
});

it('flags a second lifetime purchase for a refund instead of applying it', function () {
    $lifetime = ($this->holdLifetime)($this->starterLifetime);

    ($this->send)(($this->payment)($this->proLifetime, ['payment_id' => 'pay_twice']))->assertOk();

    $event = WebhookEvent::firstOrFail();

    expect($lifetime->fresh()->plan_price_id)->toBe($this->starterLifetime->id)
        ->and($event->failed_at)->not->toBeNull()
        ->and($event->error)->toContain('already holds a lifetime plan')
        ->and(InvoiceSummary::withoutWorkspaceScope()->where('dodo_invoice_id', 'pay_twice')->exists())->toBeTrue();
});

// ----------------------------------------------------- refunds & disputes

it('takes the lifetime plan back when its purchase is refunded in full', function () {
    $lifetime = ($this->holdLifetime)($this->proLifetime, 'pay_lt_1');
    InvoiceSummary::factory()->create([
        'workspace_id' => $this->workspace->id,
        'subscription_id' => $lifetime->id,
        'dodo_invoice_id' => 'pay_lt_1',
        'status' => 'paid',
    ]);

    ($this->send)([
        'type' => 'refund.succeeded',
        'timestamp' => now()->toIso8601String(),
        'data' => ['payment_id' => 'pay_lt_1', 'refund_id' => 'ref_1', 'is_partial' => false, 'status' => 'succeeded'],
    ])->assertOk();

    expect($lifetime->fresh()->status)->toBe(SubscriptionStatus::Canceled)
        ->and($lifetime->fresh()->ended_at)->not->toBeNull()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid)
        ->and(InvoiceSummary::withoutWorkspaceScope()->where('dodo_invoice_id', 'pay_lt_1')->value('status'))->toBe('refunded');
});

it('keeps the lifetime plan through a partial refund', function () {
    $lifetime = ($this->holdLifetime)($this->proLifetime, 'pay_lt_1');

    ($this->send)([
        'type' => 'refund.succeeded',
        'timestamp' => now()->toIso8601String(),
        'data' => ['payment_id' => 'pay_lt_1', 'refund_id' => 'ref_1', 'is_partial' => true, 'status' => 'succeeded'],
    ])->assertOk();

    expect($lifetime->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        ->and(WebhookEvent::firstOrFail()->processed_at)->not->toBeNull();
});

it('takes the lifetime plan back when a dispute over it is lost', function () {
    $lifetime = ($this->holdLifetime)($this->proLifetime, 'pay_lt_1');

    ($this->send)([
        'type' => 'dispute.lost',
        'timestamp' => now()->toIso8601String(),
        'data' => ['payment_id' => 'pay_lt_1', 'dispute_id' => 'dsp_1', 'dispute_status' => 'dispute_lost'],
    ])->assertOk();

    expect($lifetime->fresh()->status)->toBe(SubscriptionStatus::Canceled)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);
});

// They left lifetime for a subscription; refunding the old purchase must not
// take away the plan they are paying for now.
it('leaves the current plan alone when an already replaced lifetime purchase is refunded', function () {
    $lifetime = ($this->holdLifetime)($this->proLifetime, 'pay_lt_1');
    $lifetime->update(['status' => SubscriptionStatus::Expired, 'ended_at' => now()]);
    Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->proMonthly->plan_id,
        'plan_price_id' => $this->proMonthly->id,
        'dodo_subscription_id' => 'sub_after',
    ]);

    ($this->send)([
        'type' => 'refund.succeeded',
        'timestamp' => now()->toIso8601String(),
        'data' => ['payment_id' => 'pay_lt_1', 'refund_id' => 'ref_1', 'is_partial' => false, 'status' => 'succeeded'],
    ])->assertOk();

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        ->and(($this->live)()->dodo_subscription_id)->toBe('sub_after');
});

// Which plan to put them back on is a judgement, so a person makes it.
it('flags a refunded upgrade payment for staff instead of guessing', function () {
    $lifetime = ($this->holdLifetime)($this->proLifetime, 'pay_lt_1');
    InvoiceSummary::factory()->create([
        'workspace_id' => $this->workspace->id,
        'subscription_id' => $lifetime->id,
        'dodo_invoice_id' => 'pay_upgrade',
        'status' => 'paid',
    ]);

    ($this->send)([
        'type' => 'refund.succeeded',
        'timestamp' => now()->toIso8601String(),
        'data' => ['payment_id' => 'pay_upgrade', 'refund_id' => 'ref_2', 'is_partial' => false, 'status' => 'succeeded'],
    ])->assertOk();

    $event = WebhookEvent::firstOrFail();

    expect($lifetime->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($lifetime->fresh()->plan_price_id)->toBe($this->proLifetime->id)
        ->and($event->failed_at)->not->toBeNull()
        ->and($event->error)->toContain('upgrade payment was refunded')
        ->and(InvoiceSummary::withoutWorkspaceScope()->where('dodo_invoice_id', 'pay_upgrade')->value('status'))->toBe('refunded');
});
