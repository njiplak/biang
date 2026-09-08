<?php

use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\BillingSource;
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
use App\Notifications\Billing\TrialEndedUnpaidNotification;
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

/*
 * Section 4: "At the end of day 14 it charges automatically" - and since the
 * card is collected up front, DODO does that charging. What this command is
 * left with is the trial that has no card behind it: one granted by hand
 * (section 16). There is nothing to charge, so it drops to the free tier
 * exactly as a cancelled trial does.
 *
 * It used to convert these to Active regardless, which handed out the paid
 * product for free and made section 15's conversion rate meaningless.
 */
it('drops a cardless trial to the free tier rather than granting it free', function () {
    Notification::fake();
    $sub = startTrialEnding(0);
    $sub->update(['trial_ends_at' => now()->subMinute()]);

    $this->artisan('billing:convert-trials')->assertSuccessful();

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Canceled)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Unpaid);
    Notification::assertSentTo($this->owner, TrialEndedUnpaidNotification::class);
});

/*
 * The card-backed trial is Dodo's to convert, and their webhook is what does
 * it. Touching it here would either double-charge or grant paid access for
 * free, depending on which way the race went.
 */
it('leaves a card-backed trial for the provider to convert', function () {
    Notification::fake();
    $sub = startTrialEnding(0);
    $sub->update([
        'trial_ends_at' => now()->subMinute(),
        'dodo_subscription_id' => 'sub_dodo_live',
        'billing_source' => BillingSource::Dodo,
    ]);

    $this->artisan('billing:convert-trials')->assertSuccessful();

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Trialing)
        ->and($this->workspace->fresh()->billing_status)->toBe(BillingStatus::Trialing);
    Notification::assertNothingSent();
});

it('leaves a trial that still has time on it', function () {
    startTrialEnding(5);

    $this->artisan('billing:convert-trials')->assertSuccessful();

    expect(Subscription::withoutWorkspaceScope()->first()->status)->toBe(SubscriptionStatus::Trialing);
});

it('tells them their trial ended only once', function () {
    Notification::fake();
    $sub = startTrialEnding(0);
    $sub->update(['trial_ends_at' => now()->subMinute()]);

    $this->artisan('billing:convert-trials');
    $this->artisan('billing:convert-trials');

    Notification::assertSentToTimes($this->owner, TrialEndedUnpaidNotification::class, 1);
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

/*
 * Section 4 warns twice that the card WILL be charged, and section 16 is blunt
 * about why: "Auto-charging trials generate disputes if warning emails fail."
 * Warning someone twice and then saying nothing when it happens is that same
 * failure wearing a different hat.
 */
it('confirms a trial that the provider converted', function () {
    Notification::fake();
    $sub = startTrialEnding(0);
    $sub->update([
        'trial_ends_at' => now()->subMinute(),
        'status' => SubscriptionStatus::Active,
        'dodo_subscription_id' => 'sub_dodo_live',
        'billing_source' => BillingSource::Dodo,
    ]);

    $this->artisan('billing:convert-trials')->assertSuccessful();

    Notification::assertSentTo($this->owner, TrialConvertedNotification::class);
});

it('confirms a conversion only once however often it runs', function () {
    Notification::fake();
    $sub = startTrialEnding(0);
    $sub->update([
        'trial_ends_at' => now()->subMinute(),
        'status' => SubscriptionStatus::Active,
        'dodo_subscription_id' => 'sub_dodo_live',
    ]);

    $this->artisan('billing:convert-trials');
    $this->artisan('billing:convert-trials');

    Notification::assertSentToTimes($this->owner, TrialConvertedNotification::class, 1);
});

/*
 * Section 15 counts conversions as "trial_ends_at set AND status active", so a
 * comped plan must never produce that shape - it would inflate the one number
 * this whole build exists to move.
 */
it('says nothing about a comped plan that was never a trial', function () {
    Notification::fake();

    app(SubscriptionContract::class)->grantPlan(
        $this->workspace, $this->price, AdminUser::factory()->create(), 'comp'
    );

    $this->artisan('billing:convert-trials')->assertSuccessful();

    Notification::assertNothingSent();
});

// Bounded on purpose: without a window this becomes an hourly scan over every
// trial that ever converted, growing forever and finding nothing.
it('does not keep re-examining conversions from months ago', function () {
    Notification::fake();
    $sub = startTrialEnding(0);
    $sub->update([
        'trial_ends_at' => now()->subMonths(3),
        'status' => SubscriptionStatus::Active,
        'dodo_subscription_id' => 'sub_dodo_old',
    ]);

    $this->artisan('billing:convert-trials')->assertSuccessful();

    Notification::assertNothingSent();
});
