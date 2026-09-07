<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Service\Billing\DodoPaymentGateway;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 5: the billing page offers "the payment provider's page for cards and
 * invoices". Section 8 puts both of those on Dodo's side of the line, so this
 * link is the ONLY way a customer can change the card we charge.
 *
 * Section 9 is what makes it load-bearing: the past-due banner tells them to
 * update their card, and without this the instruction has no destination.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
    $this->workspace->update(['dodo_customer_id' => 'cus_dodo_1']);
});

it('hands the customer to the provider portal', function () {
    fakeGateway();

    $this->actingAs($this->owner)
        ->get(route('billing.portal'))
        ->assertRedirect('https://portal.dodopayments.test/session/abc');
});

// Section 3: admins manage people and settings, never billing. The card is
// billing, whoever's page it lives on.
it('refuses someone who may not manage billing', function () {
    fakeGateway();

    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();

    $this->actingAs($admin)
        ->get(route('billing.portal'))
        ->assertForbidden();
});

/*
 * Section 12: "Free tier in the payment provider? No - free never touches
 * them." So a free workspace has no customer id and there is nothing to open.
 * That is an ordinary state, and it has to read as a sentence rather than a
 * stack trace.
 */
it('explains itself when the workspace has never paid', function () {
    $this->workspace->update(['dodo_customer_id' => null]);
    config(['dodo.api_key' => 'key_test']);

    $this->actingAs($this->owner)
        ->from(route('billing.index'))
        ->get(route('billing.portal'))
        ->assertRedirect(route('billing.index'))
        ->assertSessionHasErrors('errors');
});

// Section 14 phase 3: the product is sellable by hand before payments exist, so
// an unconfigured provider is normal rather than broken.
it('explains itself when the provider is not configured', function () {
    config(['dodo.api_key' => null]);

    $this->actingAs($this->owner)
        ->from(route('billing.index'))
        ->get(route('billing.portal'))
        ->assertRedirect(route('billing.index'))
        ->assertSessionHasErrors('errors');
});

// The real gateway, as far as it can be taken without credentials: it must
// refuse before it ever builds a client, or the failure is a network timeout
// on a page the customer is waiting on.
it('never calls the provider without a customer to call about', function () {
    config(['dodo.api_key' => 'key_test']);
    $this->workspace->update(['dodo_customer_id' => null]);

    expect(fn () => app(DodoPaymentGateway::class)->customerPortalUrl($this->workspace, '/billing'))
        ->toThrow(App\Exceptions\Domain\PortalUnavailable::class);
});

// Section 5: the page must only offer the button when there is a page to open.
it('tells the billing page whether there is an account to open', function () {
    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertInertia(fn ($page) => $page->where('workspace.has_payment_account', true));

    $this->workspace->update(['dodo_customer_id' => null]);

    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertInertia(fn ($page) => $page->where('workspace.has_payment_account', false));
});
