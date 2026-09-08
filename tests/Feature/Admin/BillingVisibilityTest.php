<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Models\AdminUser;
use App\Models\NotificationLog;
use App\Models\UsageRecord;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;

/*
 * Two things the console recorded and never showed anyone.
 *
 * `notification_logs` is written by every billing command through
 * BillingNotifier and was read by nothing, so "you charged me with no warning"
 * was unanswerable - about a trial that auto-charges, which section 16 calls a
 * launch blocker precisely because a missed warning becomes a chargeback.
 *
 * `usage_records` carries `reported_at`, so usage we metered but never billed
 * for was already recorded as a findable state and nobody was looking.
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
    $this->other = app(WorkspaceContract::class)->create(User::factory()->create(), 'Other Co');
});

function detail(string $list, $workspace)
{
    return test()->actingAs(test()->staff, 'admin')
        ->getJson(route('admin.customer.fetch-detail', ['workspace' => $workspace, 'list' => $list]));
}

// ------------------------------------------------------- what we emailed them

it('shows what was emailed to this customer', function () {
    NotificationLog::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => 'trial_ending_3d',
        'sent_at' => now()->subDay(),
    ]);

    $response = detail('notifications', $this->workspace)->assertOk();

    expect($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.type'))->toBe('trial_ending_3d')
        ->and($response->json('items.0.sent_at'))->not->toBeNull();
});

// The whole point is answering a dispute about THIS customer.
it('never shows another customer their mail', function () {
    NotificationLog::factory()->create(['workspace_id' => $this->other->id]);

    expect(detail('notifications', $this->workspace)->assertOk()->json('items'))->toBeEmpty();
});

it('puts the most recent first, because that is the one being disputed', function () {
    NotificationLog::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => 'trial_ending_3d',
        'sent_at' => now()->subDays(3),
    ]);
    NotificationLog::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => 'trial_ending_1d',
        'sent_at' => now()->subDay(),
    ]);

    $items = detail('notifications', $this->workspace)->assertOk()->json('items');

    expect($items[0]['type'])->toBe('trial_ending_1d');
});

/*
 * The dunning reminder type is built from a day count at send time
 * ("payment_failed_reminder_7d"), so no fixed map can cover every value. An
 * unknown key has to render as itself rather than as a blank column.
 */
it('labels the milestones it knows and falls back to the raw key', function () {
    NotificationLog::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => 'trial_ending_3d',
        'sent_at' => now()->subMinute(),
    ]);
    NotificationLog::factory()->create([
        'workspace_id' => $this->workspace->id,
        'type' => 'payment_failed_reminder_7d',
        'sent_at' => now(),
    ]);

    $items = detail('notifications', $this->workspace)->assertOk()->json('items');

    expect($items[0]['label'])->toBe('Payment failed reminder 7d')
        ->and($items[1]['label'])->toBe('Trial ending in 3 days');
});

it('is empty rather than broken when we have sent nothing', function () {
    expect(detail('notifications', $this->workspace)->assertOk()->json('items'))->toBeEmpty();
});

// ------------------------------------------------------------- metered usage

it('shows the metered events behind a bill', function () {
    UsageRecord::factory()->create([
        'workspace_id' => $this->workspace->id,
        'feature_key' => 'api_calls',
        'quantity' => 42,
    ]);

    $response = detail('usage', $this->workspace)->assertOk();

    expect($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.feature'))->toBe('api_calls')
        ->and($response->json('items.0.quantity'))->toBe(42)
        ->and($response->json('items.0.is_reported'))->toBeFalse();
});

it('says which events reached the payment provider', function () {
    UsageRecord::factory()->reported()->create(['workspace_id' => $this->workspace->id]);

    $items = detail('usage', $this->workspace)->assertOk()->json('items');

    expect($items[0]['is_reported'])->toBeTrue()
        // Section 8: this is the id to quote when a charge is disputed.
        ->and($items[0]['dodo_event_id'])->not->toBeNull();
});

it('never shows another customer their usage', function () {
    UsageRecord::factory()->create(['workspace_id' => $this->other->id]);

    expect(detail('usage', $this->workspace)->assertOk()->json('items'))->toBeEmpty();
});

// -------------------------------------------------- usage we never billed for

function unreported()
{
    return test()->actingAs(test()->staff, 'admin')
        ->getJson(route('admin.billing-ops.fetch', ['list' => 'unreported_usage']));
}

it('flags usage that never reached the provider', function () {
    $this->workspace->update(['dodo_customer_id' => 'cus_123']);

    UsageRecord::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_at' => now()->subDay(),
    ]);

    $items = unreported()->assertOk()->json('items');

    expect($items)->toHaveCount(1)
        ->and($items[0]['workspace_name'])->toBe('Acme Inc');
});

/*
 * The load-bearing exclusion. ReportUsageToProvider returns early without
 * stamping `reported_at` when the workspace has no payment account - section 12
 * keeps comped and unpaid workspaces away from Dodo entirely, while section 7
 * still meters them for the hard block. Counting those as stuck would bury the
 * real ones under every free workspace we have.
 */
it('ignores usage for a workspace with nobody to bill', function () {
    UsageRecord::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_at' => now()->subDay(),
    ]);

    expect(unreported()->assertOk()->json('items'))->toBeEmpty();
});

// tries=4 on a [60, 300, 900] backoff: the last attempt is ~21 minutes out.
it('ignores usage still inside the retry window', function () {
    $this->workspace->update(['dodo_customer_id' => 'cus_123']);

    UsageRecord::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_at' => now()->subMinutes(5),
    ]);

    expect(unreported()->assertOk()->json('items'))->toBeEmpty();
});

it('ignores usage that was reported', function () {
    $this->workspace->update(['dodo_customer_id' => 'cus_123']);

    UsageRecord::factory()->reported()->create([
        'workspace_id' => $this->workspace->id,
        'created_at' => now()->subDay(),
    ]);

    expect(unreported()->assertOk()->json('items'))->toBeEmpty();
});

it('counts it on the billing-ops screen itself', function () {
    $this->workspace->update(['dodo_customer_id' => 'cus_123']);

    UsageRecord::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($this->staff, 'admin')
        ->get(route('admin.billing-ops.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('unreported_usage', 1));
});

// Section 3: the gate on the screen has to hold on the data behind it.
it('keeps the unreported list behind the revenue permission', function () {
    $support = AdminUser::factory()->create();
    $support->assignRole('support');

    $this->actingAs($support, 'admin')
        ->getJson(route('admin.billing-ops.fetch', ['list' => 'unreported_usage']))
        ->assertForbidden();
});
