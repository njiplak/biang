<?php

use App\Contract\Billing\PaymentGatewayContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingStatus;
use App\Exceptions\Domain\CheckoutUnavailable;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Service\Billing\DodoPaymentGateway;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 8: Dodo is merchant of record, so the card form is theirs. Our side
 * of checkout is only "hand them over correctly, and change nothing until the
 * webhook arrives".
 *
 * The live HTTP call to Dodo is NOT exercised here - it needs credentials this
 * project does not have yet. What is covered is everything around it: who may
 * start one, what we hand over, what happens when it is unavailable, and the
 * guarantee that our state does not move.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();
});

it('hands the customer to the provider checkout', function () {
    $this->swap(PaymentGatewayContract::class, new class implements PaymentGatewayContract
    {
        public function createCheckout(Workspace $w, PlanPrice $p, User $u, string $r, string $c): string
        {
            return 'https://checkout.dodopayments.test/session/abc';
        }
    });

    $this->actingAs($this->owner)
        ->post(route('billing.checkout'), ['plan_price_id' => $this->price->id])
        ->assertRedirect('https://checkout.dodopayments.test/session/abc');
});

/*
 * The guarantee that makes section 8's split work: nothing about access moves
 * until Dodo tells us the money moved. A customer who opens checkout and walks
 * away must not end up subscribed.
 */
it('changes nothing about our own state', function () {
    $this->swap(PaymentGatewayContract::class, new class implements PaymentGatewayContract
    {
        public function createCheckout(Workspace $w, PlanPrice $p, User $u, string $r, string $c): string
        {
            return 'https://checkout.dodopayments.test/session/abc';
        }
    });

    $this->actingAs($this->owner)
        ->post(route('billing.checkout'), ['plan_price_id' => $this->price->id]);

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Free)
        ->and($this->workspace->subscription()->withoutWorkspaceScope()->first())->toBeNull();
});

// Section 3: admins manage people, never billing.
it('refuses someone who may not manage billing', function () {
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();

    $this->actingAs($admin)
        ->post(route('billing.checkout'), ['plan_price_id' => $this->price->id])
        ->assertForbidden();
});

it('refuses an archived price', function () {
    $this->price->update(['archived_at' => now()]);

    $this->actingAs($this->owner)
        ->post(route('billing.checkout'), ['plan_price_id' => $this->price->id])
        ->assertSessionHasErrors('plan_price_id');
});

/*
 * Section 14 phase 3: the product is sellable by hand before payments exist, so
 * an unconfigured provider is a normal state that must read as a message rather
 * than an error page - staff can still grant the plan.
 */
it('explains itself when the provider is not configured', function () {
    config(['dodo.api_key' => null]);
    $this->price->update(['dodo_product_id' => 'prod_1']);

    $this->actingAs($this->owner)
        ->post(route('billing.checkout'), ['plan_price_id' => $this->price->id])
        ->assertRedirect()
        ->assertSessionHasErrors('errors');

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Free);
});

// A price nobody has published to Dodo has no product to sell.
it('refuses a price that was never published to the provider', function () {
    config(['dodo.api_key' => 'key_test']);

    expect($this->price->dodo_product_id)->toBeNull();

    expect(fn () => app(DodoPaymentGateway::class)->createCheckout(
        $this->workspace,
        $this->price,
        $this->owner,
        'https://app.test/billing',
        'https://app.test/billing',
    ))->toThrow(CheckoutUnavailable::class);
});

it('reads as a message a customer can act on', function () {
    $exception = new CheckoutUnavailable('the payment provider is not configured');

    expect($exception->userMessage())
        ->toContain('not available right now')
        ->toContain('by hand');
});
