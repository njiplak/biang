<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingSource;
use App\Enums\BillingStatus;
use App\Enums\DunningResolution;
use App\Enums\SubscriptionStatus;
use App\Models\DunningState;
use App\Models\InvoiceSummary;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use StandardWebhooks\Webhook;

/*
 * Section 8: "Our records are the source of truth for access, theirs for
 * money." Section 16: "A customer cancels on the provider's page. Our records
 * find out via notification, not immediately. We treat their notifications as
 * authoritative and reconcile."
 */

const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    config(['dodo.webhook_key' => SECRET, 'dodo.grace_days' => 14]);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    // A subscription opened by our checkout, awaiting its first notification.
    $this->subscription = Subscription::factory()->for($this->workspace)->create([
        'plan_id' => $this->price->plan_id,
        'plan_price_id' => $this->price->id,
        'status' => SubscriptionStatus::Trialing,
        'billing_source' => BillingSource::Dodo,
        'dodo_subscription_id' => 'sub_dodo_1',
        'trial_ends_at' => now()->addDays(3),
    ]);

    // Signs a body exactly as Dodo does - the SDK verifies with this same
    // library, so a body this produces is one the real verifier accepts.
    $this->send = function (array $body, ?string $secret = null, ?string $id = null) {
        $payload = json_encode($body);
        $id ??= 'msg_'.bin2hex(random_bytes(6));
        $timestamp = time();

        $signature = (new Webhook($secret ?? SECRET))->sign($id, $timestamp, $payload);

        return $this->call('POST', route('webhook.dodo'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_ID' => $id,
            'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => $signature,
        ], $payload);
    };

    $this->event = fn (string $type, array $data = [], ?string $at = null) => [
        'type' => $type,
        'business_id' => 'biz_1',
        'timestamp' => $at ?? now()->toIso8601String(),
        'data' => array_merge(['subscription_id' => 'sub_dodo_1'], $data),
    ];
});

// ------------------------------------------------------------- verification

/*
 * The whole security property of this endpoint. An event we cannot prove came
 * from Dodo must never move billing state - otherwise anyone who finds the URL
 * can activate their own subscription.
 */
it('refuses an event signed with the wrong secret', function () {
    ($this->send)(($this->event)('subscription.active'), 'whsec_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0)
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Trialing);
});

it('refuses an event with no signature at all', function () {
    $this->postJson(route('webhook.dodo'), ($this->event)('subscription.active'))
        ->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0);
});

// A missing key is a refusal, never a pass: otherwise a misconfigured deploy
// silently becomes an open endpoint.
it('refuses everything when no webhook key is configured', function () {
    config(['dodo.webhook_key' => null]);

    ($this->send)(($this->event)('subscription.active'))->assertUnauthorized();

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Trialing);
});

it('refuses a body that was tampered with after signing', function () {
    $payload = json_encode(($this->event)('subscription.active'));
    $id = 'msg_tamper';
    $timestamp = time();
    $signature = (new Webhook(SECRET))->sign($id, $timestamp, $payload);

    $this->call('POST', route('webhook.dodo'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $id,
        'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
        'HTTP_WEBHOOK_SIGNATURE' => $signature,
    ], str_replace('subscription.active', 'subscription.cancelled', $payload))
        ->assertUnauthorized();

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Trialing);
});

// ---------------------------------------------------------------- the money

// Section 4: the trial auto-charges on day 15, and this says it worked.
it('activates a subscription and settles the workspace', function () {
    ($this->send)(($this->event)('subscription.active', [
        'next_billing_date' => now()->addMonth()->toIso8601String(),
        'previous_billing_date' => now()->toIso8601String(),
    ]))->assertOk();

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->billing_source)->toBe(BillingSource::Dodo)
        ->and($subscription->current_period_end->isFuture())->toBeTrue()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        // The entitlement snapshot has to move with it, exactly as it does for
        // a plan granted by hand.
        ->and($this->workspace->fresh()->canWrite())->toBeTrue();
});

/*
 * Section 9: "Past due keeps full access." A card problem is not misuse, so
 * nothing is taken away - a grace window opens instead.
 */
it('opens a grace window on a failed payment without removing access', function () {
    ($this->send)(($this->event)('subscription.on_hold'))->assertOk();

    $workspace = $this->workspace->fresh();

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($workspace->billing_status)->toBe(BillingStatus::PastDue)
        ->and($workspace->grace_ends_at)->not->toBeNull()
        // The point of section 9.
        ->and($workspace->canWrite())->toBeTrue();

    expect(DunningState::withoutWorkspaceScope()->open()->count())->toBe(1);
});

it('honours the provider grace deadline when they send one', function () {
    $deadline = now()->addDays(5);

    ($this->send)(($this->event)('subscription.on_hold', [
        'past_due_ends_at' => $deadline->toIso8601String(),
    ]))->assertOk();

    expect($this->workspace->fresh()->grace_ends_at->toDateString())
        ->toBe($deadline->toDateString());
});

it('closes dunning and restores the plan when payment recovers', function () {
    ($this->send)(($this->event)('subscription.on_hold'))->assertOk();
    ($this->send)(($this->event)('subscription.active'))->assertOk();

    $dunning = DunningState::withoutWorkspaceScope()->firstOrFail();

    expect($dunning->resolution)->toBe(DunningResolution::Recovered)
        ->and($dunning->resolved_at)->not->toBeNull()
        ->and($this->workspace->fresh()->grace_ends_at)->toBeNull()
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active);
});

/*
 * Section 16's named risk, and section 12's rule for what cancelling means:
 * drop to the free tier, keep the data.
 */
it('drops to the free tier when they cancel on the provider page', function () {
    ($this->send)(($this->event)('subscription.cancelled', [
        'cancelled_at' => now()->toIso8601String(),
    ]))->assertOk();

    $subscription = $this->subscription->fresh();
    $workspace = $this->workspace->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subscription->canceled_at)->not->toBeNull()
        ->and($workspace->billing_status)->toBe(BillingStatus::Free)
        // Deletes nothing: the members are still there.
        ->and($workspace->members()->count())->toBe(1);
});

// Section 8: "We keep a summary; the document itself stays with the provider."
it('records an invoice summary from a successful payment', function () {
    ($this->send)(($this->event)('payment.succeeded', [
        'payment_id' => 'pay_1',
        'currency' => 'USD',
        'total_amount' => 4900,
        'tax' => 400,
        'settlement_amount' => 4500,
    ]))->assertOk();

    $invoice = InvoiceSummary::withoutWorkspaceScope()->firstOrFail();

    expect($invoice->dodo_invoice_id)->toBe('pay_1')
        ->and($invoice->total_minor)->toBe(4900)
        ->and($invoice->tax_minor)->toBe(400)
        ->and($invoice->workspace_id)->toBe($this->workspace->id)
        ->and($invoice->paid_at)->not->toBeNull();
});

it('does not duplicate an invoice when the payment event is replayed', function () {
    $body = ($this->event)('payment.succeeded', ['payment_id' => 'pay_1', 'total_amount' => 4900]);

    // Two DIFFERENT deliveries carrying the same payment.
    ($this->send)($body, null, 'msg_a')->assertOk();
    ($this->send)($body, null, 'msg_b')->assertOk();

    expect(InvoiceSummary::withoutWorkspaceScope()->count())->toBe(1);
});

// ------------------------------------------------------- delivery mechanics

// Webhooks are redelivered by design; a replay must be a no-op.
it('processes a redelivered event only once', function () {
    $body = ($this->event)('subscription.active');

    ($this->send)($body, null, 'msg_same')->assertOk();
    ($this->send)($body, null, 'msg_same')->assertOk();

    expect(WebhookEvent::count())->toBe(1);
});

/*
 * Section 8: "Webhooks arrive out of order and more than once." An `active`
 * that overtakes a later `cancelled` would silently restore access we already
 * removed.
 */
it('ignores an event older than the state already recorded', function () {
    ($this->send)(($this->event)('subscription.cancelled', [], now()->toIso8601String()))->assertOk();

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Free);

    // An activation that was actually emitted BEFORE the cancellation.
    ($this->send)(($this->event)('subscription.active', [], now()->subHour()->toIso8601String()))
        ->assertOk();

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Canceled)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Free);
});

// The trail has to be complete even for events we do not act on.
it('records an event type it does not handle without changing anything', function () {
    ($this->send)(($this->event)('dispute.opened'))->assertOk();

    expect(WebhookEvent::where('event_type', 'dispute.opened')->exists())->toBeTrue()
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Trialing);
});

/*
 * The first event for a subscription can arrive before we have stored the
 * provider's id, so checkout stamps the workspace into metadata.
 */
it('links an unknown provider id back through the checkout metadata', function () {
    $this->subscription->update(['dodo_subscription_id' => null]);

    ($this->send)([
        'type' => 'subscription.active',
        'timestamp' => now()->toIso8601String(),
        'data' => [
            'subscription_id' => 'sub_brand_new',
            'metadata' => ['workspace_ulid' => $this->workspace->ulid],
        ],
    ])->assertOk();

    expect($this->subscription->fresh()->dodo_subscription_id)->toBe('sub_brand_new')
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('records an event it cannot match to any subscription', function () {
    ($this->send)([
        'type' => 'subscription.active',
        'timestamp' => now()->toIso8601String(),
        'data' => ['subscription_id' => 'sub_nobody'],
    ])->assertOk();

    $event = WebhookEvent::firstOrFail();

    expect($event->processed_at)->toBeNull()
        ->and($event->failed_at)->not->toBeNull()
        ->and($event->error)->toContain('No matching subscription');
});

it('rejects a malformed body that still carries a valid signature', function () {
    $payload = 'not json';
    $id = 'msg_bad';
    $timestamp = time();
    $signature = (new Webhook(SECRET))->sign($id, $timestamp, $payload);

    $this->call('POST', route('webhook.dodo'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $id,
        'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
        'HTTP_WEBHOOK_SIGNATURE' => $signature,
    ], $payload)->assertStatus(400);
});
