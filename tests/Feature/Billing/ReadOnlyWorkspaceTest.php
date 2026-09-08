<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingStatus;
use App\Enums\WorkspaceRole;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * There is no free tier. A workspace nobody is paying for keeps everything it
 * has and keeps it READABLE, and that is the whole promise - there is no data
 * export, so read-only has to mean complete rather than a summary view.
 *
 * These tests are that promise. Every customer screen has to answer, every
 * write has to be refused, and the way back has to stay open.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    // Paid for, filled with real data, then cancelled - the state a customer
    // who leaves actually ends up in.
    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    app(SubscriptionContract::class)
        ->grantPlan($this->workspace, $price, AdminUser::factory()->create(), 'seed');

    WorkspaceMember::factory()->for($this->workspace)->count(3)->create();
    $this->invitation = WorkspaceInvitation::factory()->for($this->workspace)->create();

    app(SubscriptionContract::class)->cancel($this->workspace);
});

it('is read-only rather than on any kind of free plan', function () {
    $fresh = $this->workspace->fresh();

    expect($fresh->billing_status)->toBe(BillingStatus::Unpaid)
        ->and($fresh->canWrite())->toBeFalse()
        ->and($fresh->canRead())->toBeTrue()
        ->and($fresh->displayState()->label())->toBe('Read-only');
});

// Nothing is taken away to make the workspace fit a smaller allowance,
// because there is no smaller allowance - writing is simply off.
it('keeps every member and every invitation', function () {
    expect($this->workspace->fresh()->seatsUsed())->toBe(5)
        ->and(WorkspaceInvitation::where('workspace_id', $this->workspace->id)->count())->toBe(1);
});

/*
 * The load-bearing one. With no export offered, a screen that stops rendering
 * is data the customer can no longer reach at all.
 */
it('still renders every customer screen', function (string $route, array $params) {
    $this->actingAs($this->owner)
        ->get(route($route, $params === [] ? [] : [$this->workspace]))
        ->assertOk();
})->with([
    'dashboard' => ['dashboard', []],
    'members' => ['workspace.member.index', ['w']],
    'settings' => ['workspace.settings', ['w']],
    'billing' => ['billing.index', []],
]);

// The member list feed behind the table, not just the page that frames it.
it('still serves the data behind the tables', function () {
    $this->actingAs($this->owner)
        ->getJson(route('workspace.member.fetch', $this->workspace))
        ->assertOk()
        ->assertJsonStructure(['items', 'current_page', 'total_page', 'per_page'])
        // Four members (the owner plus three); the fifth seat is the pending
        // invitation, which the feed does not carry.
        ->assertJsonCount(4, 'items');
});

// Section 6 keeps export working in every state that is not deleted, and this
// is the only way the data leaves at all.
it('still exports the member list', function () {
    $this->actingAs($this->owner)
        ->get(route('workspace.member.export', $this->workspace))
        ->assertOk();
});

it('refuses every write', function () {
    $this->actingAs($this->owner)
        ->post(route('workspace.invitation.store', $this->workspace), [
            'email' => 'new@example.com',
            'role' => WorkspaceRole::Member->value,
        ])->assertForbidden();

    $this->actingAs($this->owner)
        ->put(route('workspace.update', $this->workspace), ['name' => 'Renamed'])
        ->assertForbidden();

    expect($this->workspace->fresh()->name)->toBe('Acme Inc');
});

/*
 * The way back has to stay open, and it is the one thing that must never be
 * gated on being able to write - otherwise the only route out of read-only is
 * the one read-only blocks.
 */
it('leaves the way back to a plan wide open', function () {
    $this->actingAs($this->owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.state', 'expired')
            // Every plan on offer is one they can buy.
            ->has('plans', 2));

    fakeGateway();

    // One id per row: the column is unique, because one price is one product.
    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();
    $price->update(['dodo_product_id' => 'prod_resub']);

    $this->actingAs($this->owner)
        ->post(route('billing.checkout'), ['plan_price_id' => $price->id])
        ->assertRedirect('https://checkout.dodopayments.test/session/abc');
});

// Closing is still the customer's own explicit act, and still available.
it('can still be closed by its owner', function () {
    $this->actingAs($this->owner)
        ->delete(route('workspace.destroy', $this->workspace))
        ->assertRedirect();

    // Soft-deleted, not gone: section 6 keeps a closed workspace recoverable
    // for the retention window before anything is anonymised.
    expect(App\Models\Workspace::find($this->workspace->id))->toBeNull()
        ->and(App\Models\Workspace::withTrashed()->find($this->workspace->id)->trashed())->toBeTrue();
});
