<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingStatus;
use App\Enums\DunningResolution;
use App\Enums\SubscriptionStatus;
use App\Models\AdminUser;
use App\Models\DunningState;
use App\Models\NotificationLog;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Billing\GracePeriodEndedNotification;
use App\Notifications\Billing\TrialConvertedNotification;
use App\Notifications\Billing\TrialEndingNotification;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
    $this->price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'pro'))->first();
});

function startTrialEnding(int $days): Subscription
{
    $sub = app(SubscriptionContract::class)->startTrial(test()->workspace, test()->price, test()->owner);
    $sub->update(['trial_ends_at' => now()->addDays($days)]);

    return $sub->refresh();
}

function pastDueSubscription(): Subscription
{
    app(SubscriptionContract::class)->grantPlan(
        test()->workspace, test()->price, AdminUser::factory()->create(), 'seed'
    );
    $sub = test()->workspace->fresh()->subscription;
    $sub->update(['status' => SubscriptionStatus::PastDue]);

    return $sub->refresh();
}

it('warns three days out', function () {
    Notification::fake();
    startTrialEnding(3);

    $this->artisan('billing:trial-warnings')->assertSuccessful();

    Notification::assertSentTo($this->owner, TrialEndingNotification::class);
    expect(NotificationLog::where('type', 'trial_ending_3d')->count())->toBe(1);
});

it('warns one day out', function () {
    Notification::fake();
    startTrialEnding(1);

    $this->artisan('billing:trial-warnings')->assertSuccessful();

    expect(NotificationLog::where('type', 'trial_ending_1d')->count())->toBe(1);
});

it('says nothing in the quiet middle of a trial', function () {
    Notification::fake();
    startTrialEnding(7);

    $this->artisan('billing:trial-warnings')->assertSuccessful();

    Notification::assertNothingSent();
});

// Section 16's actual risk: the scheduler runs again, or a queue retries, and
// the customer gets the same warning twice.
it('never warns twice however often it runs', function () {
    Notification::fake();
    startTrialEnding(3);

    $this->artisan('billing:trial-warnings');
    $this->artisan('billing:trial-warnings');
    $this->artisan('billing:trial-warnings');

    Notification::assertSentToTimes($this->owner, TrialEndingNotification::class, 1);
});

// Section 4: "At the end of day 14 it charges automatically." That is the whole
// point of taking the card.
it('converts a trial that has run out', function () {
    Notification::fake();
    $sub = startTrialEnding(0);
    $sub->update(['trial_ends_at' => now()->subMinute()]);

    $this->artisan('billing:convert-trials')->assertSuccessful();

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Active);
    Notification::assertSentTo($this->owner, TrialConvertedNotification::class);
});

it('leaves a trial that still has time on it', function () {
    startTrialEnding(5);

    $this->artisan('billing:convert-trials')->assertSuccessful();

    expect(Subscription::withoutWorkspaceScope()->first()->status)->toBe(SubscriptionStatus::Trialing);
});

it('converts each trial only once', function () {
    Notification::fake();
    $sub = startTrialEnding(0);
    $sub->update(['trial_ends_at' => now()->subMinute()]);

    $this->artisan('billing:convert-trials');
    $this->artisan('billing:convert-trials');

    Notification::assertSentToTimes($this->owner, TrialConvertedNotification::class, 1);
});

// Section 9: "Grace period ends → Workspace goes read-only. Email explains
// exactly why. Billing stops."
it('drops a workspace to read only when the grace period runs out', function () {
    Notification::fake();
    $sub = pastDueSubscription();

    DunningState::create([
        'workspace_id' => $this->workspace->id,
        'subscription_id' => $sub->id,
        'started_at' => now()->subDays(20),
        'grace_ends_at' => now()->subDay(),
    ]);

    $this->artisan('billing:expire-grace')->assertSuccessful();

    $fresh = $this->workspace->fresh();
    expect($fresh->canWrite())->toBeFalse()
        ->and($fresh->canRead())->toBeTrue()
        ->and($fresh->canExport())->toBeTrue();

    Notification::assertSentTo($this->owner, GracePeriodEndedNotification::class);
});

it('leaves a grace period that is still running', function () {
    $sub = pastDueSubscription();

    DunningState::create([
        'workspace_id' => $this->workspace->id,
        'subscription_id' => $sub->id,
        'started_at' => now(),
        'grace_ends_at' => now()->addDays(5),
    ]);

    $this->artisan('billing:expire-grace')->assertSuccessful();

    expect($this->workspace->fresh()->canWrite())->toBeTrue();
});

it('closes out the dunning episode it acted on', function () {
    Notification::fake();
    $sub = pastDueSubscription();
    $dunning = DunningState::create([
        'workspace_id' => $this->workspace->id,
        'subscription_id' => $sub->id,
        'started_at' => now()->subDays(20),
        'grace_ends_at' => now()->subDay(),
    ]);

    $this->artisan('billing:expire-grace');

    expect($dunning->fresh()->resolution)->toBe(DunningResolution::Suspended)
        ->and($dunning->fresh()->resolved_at)->not->toBeNull();
});

// Section 6: recoverable for the retention window, then anonymised rather than
// hard-deleted "so revenue history survives".
it('anonymises a closed workspace once the retention window passes', function () {
    app(WorkspaceContract::class)->closeWorkspace($this->workspace);
    Workspace::withTrashed()->find($this->workspace->id)->update(['purge_after' => now()->subDay()]);

    $this->artisan('workspaces:purge')->assertSuccessful();

    $purged = Workspace::withTrashed()->find($this->workspace->id);

    expect($purged)->not->toBeNull()
        ->and($purged->anonymized_at)->not->toBeNull()
        ->and($purged->name)->not->toBe('Acme Inc');
});

it('leaves a closed workspace alone inside the retention window', function () {
    app(WorkspaceContract::class)->closeWorkspace($this->workspace);

    $this->artisan('workspaces:purge')->assertSuccessful();

    expect(Workspace::withTrashed()->find($this->workspace->id)->anonymized_at)->toBeNull();
});

it('anonymises only once', function () {
    app(WorkspaceContract::class)->closeWorkspace($this->workspace);
    Workspace::withTrashed()->find($this->workspace->id)->update(['purge_after' => now()->subDay()]);

    $this->artisan('workspaces:purge');
    $first = Workspace::withTrashed()->find($this->workspace->id)->anonymized_at;
    $this->artisan('workspaces:purge');

    expect(Workspace::withTrashed()->find($this->workspace->id)->anonymized_at->eq($first))->toBeTrue();
});
