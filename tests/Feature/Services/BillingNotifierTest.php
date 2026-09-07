<?php

use App\Contract\Billing\BillingNotifierContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\WorkspaceRole;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Notifications\Billing\TrialEndingNotification;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(FeatureSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->notifier = app(BillingNotifierContract::class);
    $this->owner = User::factory()->create();
    $this->workspace = app(WorkspaceContract::class)->create($this->owner, 'Acme Inc');
});

// Section 9: "The owner and any billing manager get these emails. Regular
// members do not - they should not learn about their company's card problems
// from us."
it('emails only the owner and billing managers', function () {
    Notification::fake();

    $billing = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($billing)->billingManager()->create();

    $admin = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($admin)->admin()->create();

    $member = User::factory()->create();
    WorkspaceMember::factory()->for($this->workspace)->for($member)->create();

    $this->notifier->sendOnce(
        $this->workspace,
        'trial_ending_3d',
        'trial_ending_3d:test',
        fn () => new TrialEndingNotification($this->workspace, 3),
    );

    Notification::assertSentTo([$this->owner, $billing], TrialEndingNotification::class);
    Notification::assertNotSentTo([$admin, $member], TrialEndingNotification::class);
});

// Section 16: because the trial auto-charges, a duplicated or missed warning
// turns a conversion into a chargeback. The dedupe key is what makes a re-run
// of the scheduled command physically unable to send twice.
it('sends once no matter how often it is asked', function () {
    Notification::fake();

    foreach (range(1, 4) as $ignored) {
        $this->notifier->sendOnce(
            $this->workspace,
            'trial_ending_3d',
            'trial_ending_3d:sub_1',
            fn () => new TrialEndingNotification($this->workspace, 3),
        );
    }

    Notification::assertSentToTimes($this->owner, TrialEndingNotification::class, 1);
    expect(NotificationLog::where('dedupe_key', 'trial_ending_3d:sub_1')->count())->toBe(1);
});

it('records what was sent and to which workspace', function () {
    Notification::fake();

    $this->notifier->sendOnce(
        $this->workspace,
        'trial_ending_1d',
        'trial_ending_1d:sub_9',
        fn () => new TrialEndingNotification($this->workspace, 1),
    );

    $log = NotificationLog::firstWhere('dedupe_key', 'trial_ending_1d:sub_9');

    expect($log->type)->toBe('trial_ending_1d')
        ->and($log->workspace_id)->toBe($this->workspace->id)
        ->and($log->sent_at)->not->toBeNull();
});

it('treats a different milestone as a different send', function () {
    Notification::fake();

    $this->notifier->sendOnce($this->workspace, 'trial_ending_3d', 'trial_ending_3d:s1', fn () => new TrialEndingNotification($this->workspace, 3));
    $this->notifier->sendOnce($this->workspace, 'trial_ending_1d', 'trial_ending_1d:s1', fn () => new TrialEndingNotification($this->workspace, 1));

    Notification::assertSentToTimes($this->owner, TrialEndingNotification::class, 2);
});

it('sends nothing when nobody is entitled to billing mail', function () {
    Notification::fake();

    // demote the only owner is impossible, so use a workspace of viewers
    $bare = app(WorkspaceContract::class)->create(User::factory()->create(), 'Bare Co');
    $bare->owners()->first()->update(['role' => WorkspaceRole::Viewer]);

    $this->notifier->sendOnce($bare, 'trial_ending_3d', 'x:1', fn () => new TrialEndingNotification($bare, 3));

    Notification::assertNothingSent();
    expect(NotificationLog::where('dedupe_key', 'x:1')->exists())->toBeFalse();
});
