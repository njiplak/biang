<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkspaceMember;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Section 4 prices a plan change as "the price difference is prorated", and
 * section 8 makes that arithmetic Dodo's - so they are the only ones who can
 * say what it comes to.
 *
 * We charged it without ever showing it, which is the most reliable way to
 * turn an upgrade into a "why was I charged this?" ticket.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->gateway = fakeGateway();

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    PlanPrice::query()->get()->each(fn (PlanPrice $price) => $price->update([
        'dodo_product_id' => 'prod_'.$price->id,
    ]));

    $this->starter = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))
        ->where('billing_interval', 'month')->firstOrFail();
    $this->pro = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->firstOrFail();

    app(SubscriptionContract::class)
        ->grantPlan($this->workspace, $this->starter, AdminUser::factory()->create(), 'seed');

    Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->id)
        ->update(['dodo_subscription_id' => 'sub_1']);
});

it('quotes what the switch will cost before it is made', function () {
    $this->gateway->preview = [
        'amount_minor' => 1200,
        'currency' => 'USD',
        'tax_minor' => 0,
        'credit_minor' => 2500,
    ];

    $this->actingAs($this->owner)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->pro->id]))
        ->assertOk()
        ->assertJsonPath('preview.amount_minor', 1200)
        // Shown separately because "you are charged 12" reads very differently
        // from "37 less 25 of credit for what you have not used".
        ->assertJsonPath('preview.credit_minor', 2500);
});

// Asking the price must never move anything.
it('changes nothing by asking', function () {
    $this->actingAs($this->owner)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->pro->id]))
        ->assertOk();

    expect($this->workspace->fresh()->subscription->plan->code)->toBe('starter')
        ->and($this->gateway->planChanges)->toBeEmpty();
});

/*
 * The quote has to refuse exactly what the change would refuse, or the dialog
 * puts a price on a move that is about to be blocked.
 */
it('refuses to price a plan that cannot hold the people already here', function () {
    WorkspaceMember::factory()->for($this->workspace)->count(6)->create();

    $this->actingAs($this->owner)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->starter->id]))
        ->assertStatus(422);
});

/*
 * A first purchase has nothing to prorate against - the checkout prices itself
 * - and a comped plan has no money behind it at all. Both answer "no quote"
 * rather than failing, so the page can still offer the change.
 */
it('has no quote for a workspace with nothing to change from', function () {
    $fresh = app(WorkspaceContract::class)->create(User::factory()->create(), 'Beta Ltd');
    $buyer = $fresh->owners()->first()->user;

    $this->actingAs($buyer)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->pro->id]))
        ->assertOk()
        ->assertJsonPath('preview', null);
});

it('has no quote for a plan granted by hand', function () {
    Subscription::withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->id)
        ->update(['dodo_subscription_id' => null]);

    $this->actingAs($this->owner)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->pro->id]))
        ->assertOk()
        ->assertJsonPath('preview', null);
});

// Section 3: admins manage people, never billing.
it('refuses someone who may not manage billing', function () {
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();

    $this->actingAs($admin)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->pro->id]))
        ->assertForbidden();
});

/*
 * A provider that cannot price the change must not stop the customer making
 * it. The dialog says the change is prorated without a number, which is
 * exactly where we were before any of this existed.
 */
it('fails loudly enough for the page to fall back', function () {
    $this->gateway->broken();

    $this->actingAs($this->owner)
        ->getJson(route('billing.plan.preview', ['plan_price_id' => $this->pro->id]))
        ->assertStatus(422);

    // The switch itself is untouched and still theirs to make.
    expect($this->workspace->fresh()->subscription->plan->code)->toBe('starter');
});
