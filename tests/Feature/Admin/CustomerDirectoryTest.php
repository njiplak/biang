<?php

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Billing\UsageContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\AccessStatus;
use App\Enums\BillingSource;
use App\Enums\BillingStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\WorkspaceEntitlementOverride;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia;

/*
 * Section 10: "Answer a support ticket in under a minute. Find any customer by
 * email or workspace name. See their plan, state, seat usage and payment
 * history without opening a second tool."
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create(['email' => 'jo@acme-test.com']);
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');

    $this->proPrice = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))
        ->where('billing_interval', 'month')->first();

    $this->staff = function (string $role) {
        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        return $admin;
    };

    $this->superAdmin = ($this->staff)('super-admin');
});

// -------------------------------------------------------------- the directory

it('finds a customer by workspace name', function () {
    $this->actingAs($this->superAdmin, 'admin')
        ->getJson(route('admin.customer.fetch', ['filter' => ['search' => 'Acme']]))
        ->assertOk()
        ->assertJsonPath('items.0.name', 'Acme Inc')
        ->assertJsonPath('items.0.state', 'free');
});

// Section 10 says "by email OR workspace name", and support is usually handed
// the email off a ticket.
it('finds a customer by a member email', function () {
    $this->actingAs($this->superAdmin, 'admin')
        ->getJson(route('admin.customer.fetch', ['filter' => ['search' => 'jo@acme-test.com']]))
        ->assertOk()
        ->assertJsonPath('items.0.name', 'Acme Inc');
});

it('returns nothing for a term that matches no customer', function () {
    $this->actingAs($this->superAdmin, 'admin')
        ->getJson(route('admin.customer.fetch', ['filter' => ['search' => 'nobody-by-this-name']]))
        ->assertOk()
        ->assertJsonCount(0, 'items');
});

/*
 * "Find ANY customer." A ticket about a workspace that vanished is exactly when
 * staff need to open it, so the directory reads through the soft delete.
 */
it('still finds a closed workspace', function () {
    app(WorkspaceContract::class)->closeWorkspace($this->workspace);

    $this->actingAs($this->superAdmin, 'admin')
        ->getJson(route('admin.customer.fetch', ['filter' => ['search' => 'Acme']]))
        ->assertOk()
        ->assertJsonPath('items.0.state', 'deleted');
});

it('opens a closed workspace on the detail screen', function () {
    app(WorkspaceContract::class)->closeWorkspace($this->workspace);

    $this->actingAs($this->superAdmin, 'admin')
        ->get(route('admin.customer.show', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/customer/show')
            ->where('workspace.state', 'deleted'));
});

/*
 * The detail route resolves a trashed workspace; the action routes deliberately
 * do not. Suspending or comping a closed workspace is not a thing staff should
 * be able to do by hand, and the screen hides those buttons on the strength of
 * this.
 */
it('refuses to act on a closed workspace', function () {
    app(WorkspaceContract::class)->closeWorkspace($this->workspace);

    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.customer.suspend', $this->workspace), ['reason' => 'Too late'])
        ->assertNotFound();

    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.customer.plan', $this->workspace), [
            'plan_price_id' => $this->proPrice->id,
            'reason' => 'Too late',
        ])
        ->assertNotFound();
});

it('shows plan, state, seats and members on one screen', function () {
    app(SubscriptionContract::class)->grantPlan($this->workspace, $this->proPrice, $this->superAdmin, 'Launch partner');

    $this->actingAs($this->superAdmin, 'admin')
        ->get(route('admin.customer.show', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/customer/show')
            ->where('workspace.name', 'Acme Inc')
            ->where('workspace.state', 'active')
            ->where('subscription.plan_code', 'pro')
            // Section 8: a comp has no payment behind it, and support has to be
            // able to tell that from a broken sync at a glance.
            ->where('subscription.billing_source', BillingSource::Manual->value)
            ->where('subscription.grant_reason', 'Launch partner')
            ->where('seats.used', 1)
            ->has('members', 1)
            ->has('entitlements')
            ->has('plans')
            ->has('features')
            ->has('invoices')
            ->has('overrides'));
});

/*
 * Section 3 separates the guards but does not stop both holding a session in
 * one browser - AdminAuthTest asserts exactly that. If the console read through
 * the tenancy scope, a staff member who is also a customer would silently see
 * their OWN workspace's subscription on every customer they opened.
 */
it('reads the target workspace even when the staff member also holds a customer session', function () {
    // Two DIFFERENT plans, so a scoped read cannot accidentally look right.
    app(SubscriptionContract::class)->grantPlan($this->workspace, $this->proPrice, $this->superAdmin, 'Theirs');

    $starterPrice = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))
        ->where('billing_interval', 'month')->first();

    $ownWorkspace = app(WorkspaceContract::class)->create(
        $staffAsCustomer = User::factory()->create(),
        'Staff Side Project',
    );
    app(SubscriptionContract::class)->grantPlan($ownWorkspace, $starterPrice, $this->superAdmin, 'Own');

    // The customer session sets the ambient workspace via ResolveWorkspace.
    $this->actingAs($staffAsCustomer, 'web');

    $this->actingAs($this->superAdmin, 'admin')
        ->get(route('admin.customer.show', $this->workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspace.name', 'Acme Inc')
            // Read through the tenancy scope this is null - the staff member's
            // own workspace is the ambient one, and Acme's row is filtered out.
            ->where('subscription.plan_code', 'pro')
            ->where('subscription.grant_reason', 'Theirs')
            ->where('seats.limit', 25));
});

// ---------------------------------------------------------------- stop abuse

it('suspends a workspace with a reason on record', function () {
    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.customer.suspend', $this->workspace), ['reason' => 'Spam reports'])
        ->assertRedirect();

    $fresh = $this->workspace->fresh();

    expect($fresh->access_status)->toBe(AccessStatus::Suspended)
        ->and($fresh->suspension_reason)->toBe('Spam reports')
        ->and($fresh->suspended_by_admin_id)->toBe($this->superAdmin->id)
        ->and($fresh->canWrite())->toBeFalse()
        // Section 6: a suspended workspace can still export everything.
        ->and($fresh->canExport())->toBeTrue();
});

it('refuses to suspend without a reason', function () {
    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.customer.suspend', $this->workspace), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($this->workspace->fresh()->access_status)->toBe(AccessStatus::Active);
});

it('lifts a suspension', function () {
    app(WorkspaceContract::class)->suspend($this->workspace, $this->superAdmin, 'Spam');

    $this->actingAs($this->superAdmin, 'admin')
        ->delete(route('admin.customer.unsuspend', $this->workspace))
        ->assertRedirect();

    expect($this->workspace->fresh()->access_status)->toBe(AccessStatus::Active)
        ->and($this->workspace->fresh()->suspension_reason)->toBeNull();
});

// --------------------------------------------------------------- close a deal

it('grants a plan by hand with no payment behind it', function () {
    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.customer.plan', $this->workspace), [
            'plan_price_id' => $this->proPrice->id,
            'reason' => 'Closed over a call',
        ])
        ->assertRedirect();

    $subscription = $this->workspace->subscription()->withoutWorkspaceScope()->first();

    expect($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active)
        ->and($subscription->billing_source)->toBe(BillingSource::Manual)
        ->and($subscription->granted_by_admin_id)->toBe($this->superAdmin->id)
        ->and($subscription->grant_reason)->toBe('Closed over a call');
});

/*
 * Staff think of this as one job. A workspace that already has a subscription
 * gets moved rather than refused - the alternative is sales hitting
 * WorkspaceAlreadySubscribed and having to know which verb we wanted.
 */
it('moves an existing subscription instead of refusing', function () {
    app(SubscriptionContract::class)->grantPlan($this->workspace, $this->proPrice, $this->superAdmin, 'First');

    $starterPrice = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'starter'))
        ->where('billing_interval', 'month')->first();

    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.customer.plan', $this->workspace), [
            'plan_price_id' => $starterPrice->id,
            'reason' => 'Downsized',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->workspace->subscription()->withoutWorkspaceScope()->first()->plan_id)
        ->toBe($starterPrice->plan_id);
});

it('refuses to grant an archived price', function () {
    $this->proPrice->update(['archived_at' => now()]);

    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.customer.plan', $this->workspace), [
            'plan_price_id' => $this->proPrice->id,
            'reason' => 'Old deal',
        ])
        ->assertSessionHasErrors('plan_price_id');
});

it('extends a running trial', function () {
    app(SubscriptionContract::class)->startTrial($this->workspace, $this->proPrice, $this->owner);
    $before = $this->workspace->subscription()->withoutWorkspaceScope()->first()->trial_ends_at;

    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.customer.trial', $this->workspace), [
            'days' => 7,
            'reason' => 'Security review still running',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $after = $this->workspace->subscription()->withoutWorkspaceScope()->first();

    expect($after->trial_ends_at->toDateString())->toBe($before->addDays(7)->toDateString())
        ->and($after->status)->toBe(SubscriptionStatus::Trialing);
});

it('explains that a workspace with no trial cannot have one extended', function () {
    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.customer.trial', $this->workspace), ['days' => 7, 'reason' => 'Please'])
        ->assertSessionHasErrors('errors');
});

// ------------------------------------------------------------ override a limit

it('overrides a limit for one customer and lifts the block', function () {
    $seats = Feature::where('key', 'seats')->firstOrFail();

    app(UsageContract::class)->setGauge($this->workspace, 'seats', 9);
    app(UsageContract::class)->evaluate($this->workspace);
    expect($this->workspace->fresh()->isOverLimit())->toBeTrue();

    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.customer.override.store', $this->workspace), [
            'feature_id' => $seats->id,
            'value' => 50,
            'reason' => 'Enterprise pilot',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(EntitlementContract::class)->limitFor($this->workspace, 'seats'))->toBe(50)
        ->and($this->workspace->fresh()->isOverLimit())->toBeFalse();
});

it('accepts a null override as unlimited', function () {
    $seats = Feature::where('key', 'seats')->firstOrFail();

    $this->actingAs($this->superAdmin, 'admin')
        ->post(route('admin.customer.override.store', $this->workspace), [
            'feature_id' => $seats->id,
            'value' => null,
            'reason' => 'Comped',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(EntitlementContract::class)->limitFor($this->workspace, 'seats'))->toBeNull();
});

it('revokes an override', function () {
    $seats = Feature::where('key', 'seats')->firstOrFail();
    $override = app(EntitlementContract::class)
        ->override($this->workspace, $seats, 50, $this->superAdmin, 'Pilot');

    $this->actingAs($this->superAdmin, 'admin')
        ->delete(route('admin.customer.override.destroy', [$this->workspace, $override->id]))
        ->assertRedirect();

    expect($override->fresh()->revoked_at)->not->toBeNull();
});

/*
 * The override is looked up by id under the workspace in the URL, so this is
 * the check that one customer's override cannot be revoked from another
 * customer's page.
 */
it('refuses to revoke an override belonging to another workspace', function () {
    $other = app(WorkspaceContract::class)->create(User::factory()->create(), 'Other Co');
    $seats = Feature::where('key', 'seats')->firstOrFail();

    $override = WorkspaceEntitlementOverride::withoutWorkspaceScope()->create([
        'workspace_id' => $other->id,
        'feature_id' => $seats->id,
        'value' => 50,
        'reason' => 'Theirs',
        'granted_by_admin_id' => $this->superAdmin->id,
    ]);

    $this->actingAs($this->superAdmin, 'admin')
        ->delete(route('admin.customer.override.destroy', [$this->workspace, $override->id]))
        ->assertNotFound();

    expect($override->fresh()->revoked_at)->toBeNull();
});

// ------------------------------------------------------------------ staff RBAC

it('keeps a customer out of the directory entirely', function () {
    $this->actingAs($this->owner)
        ->get(route('admin.customer.index'))
        ->assertRedirect(route('admin.login'));
});

// Section 10 splits these jobs across roles on purpose: support answers tickets
// and stops abuse, sales closes deals.
it('lets support view and suspend but not grant a plan', function () {
    $support = ($this->staff)('support');

    $this->actingAs($support, 'admin')->get(route('admin.customer.index'))->assertOk();
    $this->actingAs($support, 'admin')
        ->post(route('admin.customer.suspend', $this->workspace), ['reason' => 'Abuse'])
        ->assertRedirect();

    $this->actingAs($support, 'admin')
        ->post(route('admin.customer.plan', $this->workspace), [
            'plan_price_id' => $this->proPrice->id,
            'reason' => 'Nope',
        ])
        ->assertForbidden();
});

it('lets sales grant a plan and override a limit but not suspend', function () {
    $sales = ($this->staff)('sales');

    $this->actingAs($sales, 'admin')
        ->post(route('admin.customer.plan', $this->workspace), [
            'plan_price_id' => $this->proPrice->id,
            'reason' => 'Closed',
        ])
        ->assertRedirect();

    $this->actingAs($sales, 'admin')
        ->post(route('admin.customer.suspend', $this->workspace), ['reason' => 'Nope'])
        ->assertForbidden();
});

it('refuses a staff member with no customer permission at all', function () {
    $admin = AdminUser::factory()->create();

    $this->actingAs($admin, 'admin')->get(route('admin.customer.index'))->assertForbidden();
});
