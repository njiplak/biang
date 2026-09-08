<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\AdminUser;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WorkspaceMember;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Every admin list is now a NextTable, and NextTable is driven by a `fetch`
 * endpoint returning the paginated `Base<T[]>` envelope. Three screens gained
 * one: scheduler, billing-ops and the customer detail page.
 *
 * The `list` parameter on the last two selects a query from a query string, so
 * what it accepts is a security property, not a convenience.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->seed(AdminRoleSeeder::class);

    $this->staff = AdminUser::factory()->create();
    $this->staff->assignRole('super-admin');

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

// ------------------------------------------------------------- billing ops

it('serves each billing-ops list in the table envelope', function (string $list) {
    $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.billing-ops.fetch', ['list' => $list]))
        ->assertOk()
        ->assertJsonStructure(['items', 'current_page', 'next_page', 'prev_page', 'total_page']);
})->with(['failed_webhooks', 'dunning', 'integrity', 'recent_webhooks', 'unreported_usage']);

/*
 * The value picks a query. Anything outside the allow-list has to be refused
 * by validation rather than reaching a match() and throwing a 500 - or worse,
 * being turned into a method name.
 */
it('refuses a list it does not recognise', function () {
    $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.billing-ops.fetch', ['list' => 'users']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('list');
});

it('refuses a fetch with no list at all', function () {
    $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.billing-ops.fetch'))
        ->assertStatus(422);
});

it('actually paginates rather than returning everything', function () {
    WebhookEvent::factory()->count(7)->create([
        'failed_at' => now(),
        'processed_at' => null,
        'signature_verified' => true,
    ]);

    $response = $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.billing-ops.fetch', ['list' => 'failed_webhooks', 'per_page' => 3]))
        ->assertOk();

    expect($response->json('items'))->toHaveCount(3)
        ->and($response->json('next_page'))->toBe(2)
        ->and($response->json('prev_page'))->toBeNull()
        ->and($response->json('total_page'))->toBe(3);
});

it('searches within a list', function () {
    WebhookEvent::factory()->create([
        'event_type' => 'subscription.cancelled',
        'failed_at' => now(), 'processed_at' => null, 'signature_verified' => true,
    ]);
    WebhookEvent::factory()->create([
        'event_type' => 'payment.failed',
        'failed_at' => now(), 'processed_at' => null, 'signature_verified' => true,
    ]);

    $response = $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.billing-ops.fetch', [
            'list' => 'failed_webhooks',
            'filter' => ['search' => 'payment'],
        ]))
        ->assertOk();

    expect($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.event_type'))->toBe('payment.failed');
});

// Section 3: staff RBAC still applies to the data behind a screen, not just
// the screen. An endpoint that answers to anyone makes the gate decorative.
it('refuses a fetch from staff without the permission', function () {
    $support = AdminUser::factory()->create();

    $this->actingAs($support, 'admin')
        ->getJson(route('admin.billing-ops.fetch', ['list' => 'dunning']))
        ->assertForbidden();
});

/*
 * Section 3: "A customer account can never reach admin functions." For an XHR
 * that is a 401 rather than a redirect - the table is fetching JSON, and being
 * handed the login PAGE would look like a successful response full of HTML.
 */
it('refuses a fetch from a customer account', function () {
    $this->actingAs($this->owner)
        ->getJson(route('admin.billing-ops.fetch', ['list' => 'dunning']))
        ->assertUnauthorized();
});

// --------------------------------------------------------------- scheduler

it('serves the scheduler tasks in the table envelope', function () {
    $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.scheduler.fetch'))
        ->assertOk()
        ->assertJsonStructure(['items', 'current_page', 'total_page']);
});

// ---------------------------------------------------- customer detail lists

it('serves each customer detail list in the table envelope', function (string $list) {
    $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.customer.fetch-detail', ['workspace' => $this->workspace, 'list' => $list]))
        ->assertOk()
        ->assertJsonStructure(['items', 'current_page', 'total_page']);
})->with(['members', 'entitlements', 'overrides', 'invoices', 'notifications', 'usage']);

it('returns the workspace members through the table', function () {
    WorkspaceMember::factory()->for($this->workspace)->count(2)->create();

    $response = $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.customer.fetch-detail', ['workspace' => $this->workspace, 'list' => 'members']))
        ->assertOk();

    // The owner plus the two added above.
    expect($response->json('items'))->toHaveCount(3)
        ->and($response->json('items.0'))->toHaveKeys(['id', 'user_id', 'name', 'email', 'role', 'is_owner']);
});

it('refuses a customer list it does not recognise', function () {
    $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.customer.fetch-detail', ['workspace' => $this->workspace, 'list' => 'secrets']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('list');
});

/*
 * show() is deliberately withTrashed - "a ticket about a workspace that
 * vanished is exactly when support needs to open it" - so the tables on that
 * page have to load for a closed workspace too, or the page half-renders.
 */
it('still serves the lists for a closed workspace', function () {
    $this->workspace->delete();

    $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.customer.fetch-detail', ['workspace' => $this->workspace->ulid, 'list' => 'members']))
        ->assertOk();
});

it('scopes a detail list to its own workspace', function () {
    $other = app(WorkspaceContract::class)->create(User::factory()->create(), 'Other Co');
    WorkspaceMember::factory()->for($other)->count(3)->create();

    $response = $this->actingAs($this->staff, 'admin')
        ->getJson(route('admin.customer.fetch-detail', ['workspace' => $this->workspace, 'list' => 'members']))
        ->assertOk();

    // Only Acme's single owner - never Other Co's people.
    expect($response->json('items'))->toHaveCount(1);
});
