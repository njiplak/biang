<?php

use App\Contract\Workspace\WorkspaceContract;
use App\Enums\DunningResolution;
use App\Enums\SubscriptionStatus;
use App\Models\DunningState;
use App\Models\NotificationLog;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Notifications\Billing\PaymentFailedNotification;
use App\Notifications\Billing\PaymentFailedReminderNotification;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Section 9's first two rows: "Renewal fails → Email + banner in the app. Full
 * access continues", then "Still failing → Reminder emails escalate in tone."
 *
 * The banner was already built. What is covered here is the email, which is the
 * half that reaches a customer who is not opening the app - which is usually
 * the reason they have not noticed the card expired.
 */

beforeEach(function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);

    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

/** Opens a failed-payment episode that started $daysAgo, as DodoReconciler does. */
function openEpisode(int $daysAgo = 0, int $graceDays = 14): DunningState
{
    $subscription = Subscription::factory()->for(test()->workspace)->create([
        'status' => SubscriptionStatus::PastDue,
    ]);

    return DunningState::factory()->create([
        'workspace_id' => test()->workspace->id,
        'subscription_id' => $subscription->id,
        'started_at' => now()->subDays($daysAgo),
        'grace_ends_at' => now()->subDays($daysAgo)->addDays($graceDays),
    ]);
}

it('tells the workspace its payment failed', function () {
    Notification::fake();
    openEpisode();

    $this->artisan('billing:dunning-reminders')->assertSuccessful();

    Notification::assertSentTo($this->owner, PaymentFailedNotification::class);
    expect(NotificationLog::where('type', 'payment_failed')->count())->toBe(1);
});

// Section 9: "The owner and any billing manager get these emails. Regular
// members do not - they should not learn about their company's card problems
// from us."
it('spares regular members the news', function () {
    Notification::fake();

    $member = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($member)->create();

    openEpisode();

    $this->artisan('billing:dunning-reminders');

    Notification::assertSentTo($this->owner, PaymentFailedNotification::class);
    Notification::assertNotSentTo($member, PaymentFailedNotification::class);
});

/*
 * The actual risk. Dodo retries one failed renewal several times, and this runs
 * every hour on top of that. Section 9 promises ONE "your payment failed", not
 * one per attempt - and a customer emailed hourly about a declined card cancels.
 */
it('says it once however often it runs', function () {
    Notification::fake();
    openEpisode();

    $this->artisan('billing:dunning-reminders');
    $this->artisan('billing:dunning-reminders');
    $this->artisan('billing:dunning-reminders');

    Notification::assertSentToTimes($this->owner, PaymentFailedNotification::class, 1);
});

it('says nothing when no payment has failed', function () {
    Notification::fake();

    $this->artisan('billing:dunning-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

// Section 9 row 2. The escalation is the DEADLINE getting closer, which is why
// it is dated from when the episode opened rather than from this run.
it('escalates three days in', function () {
    Notification::fake();
    $episode = openEpisode(daysAgo: 3);

    // The run that sends the first email deliberately stops there.
    $this->artisan('billing:dunning-reminders');
    Notification::assertSentToTimes($this->owner, PaymentFailedReminderNotification::class, 0);

    $this->artisan('billing:dunning-reminders');

    Notification::assertSentTo($this->owner, PaymentFailedReminderNotification::class);
    expect(NotificationLog::where('dedupe_key', "payment_failed_reminder:dunning_{$episode->id}:day_3")->count())->toBe(1);
});

it('escalates again a week in', function () {
    Notification::fake();
    $episode = openEpisode(daysAgo: 7);

    $this->artisan('billing:dunning-reminders');
    $this->artisan('billing:dunning-reminders');

    // Both milestones are due at seven days, and both are owed.
    expect(NotificationLog::where('dedupe_key', "payment_failed_reminder:dunning_{$episode->id}:day_3")->count())->toBe(1)
        ->and(NotificationLog::where('dedupe_key', "payment_failed_reminder:dunning_{$episode->id}:day_7")->count())->toBe(1);
});

it('stays quiet in the first days', function () {
    Notification::fake();
    openEpisode(daysAgo: 1);

    $this->artisan('billing:dunning-reminders');
    $this->artisan('billing:dunning-reminders');

    Notification::assertSentToTimes($this->owner, PaymentFailedNotification::class, 1);
    Notification::assertSentToTimes($this->owner, PaymentFailedReminderNotification::class, 0);
});

/*
 * An episode that is already old the first time this command sees it - which is
 * every open episode on the day this ships. Three emails in one minute is how a
 * billing problem becomes a support ticket.
 */
it('does not empty the whole backlog into one run', function () {
    Notification::fake();
    openEpisode(daysAgo: 9);

    $this->artisan('billing:dunning-reminders');

    Notification::assertSentToTimes($this->owner, PaymentFailedNotification::class, 1);
    Notification::assertSentToTimes($this->owner, PaymentFailedReminderNotification::class, 0);
});

/*
 * Past the grace window, ExpireDunningGrace has already told them the workspace
 * is read-only. A reminder offering them time they no longer have contradicts
 * an email they may have open in front of them.
 */
it('stops reminding once the grace window is spent', function () {
    Notification::fake();
    openEpisode(daysAgo: 20, graceDays: 14);

    $this->artisan('billing:dunning-reminders');
    $this->artisan('billing:dunning-reminders');

    Notification::assertSentToTimes($this->owner, PaymentFailedNotification::class, 1);
    Notification::assertSentToTimes($this->owner, PaymentFailedReminderNotification::class, 0);
});

// Section 9 last row: "Card fixed → Access restored immediately." The reconciler
// resolves the episode, and that has to be the end of the emails too.
it('goes silent the moment the payment is recovered', function () {
    Notification::fake();
    $episode = openEpisode(daysAgo: 3);

    $this->artisan('billing:dunning-reminders');

    $episode->update(['resolved_at' => now(), 'resolution' => DunningResolution::Recovered]);

    $this->artisan('billing:dunning-reminders');

    Notification::assertSentToTimes($this->owner, PaymentFailedReminderNotification::class, 0);
});
