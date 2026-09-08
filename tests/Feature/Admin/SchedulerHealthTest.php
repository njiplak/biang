<?php

use App\Contract\Admin\SchedulerHealthContract;
use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;

/*
 * Section 16: "Trial-ending emails are a launch blocker, not a nice-to-have."
 * bootstrap/app.php monitors all four tasks precisely because "a scheduler that
 * silently stops looks exactly like a scheduler with nothing to do".
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(AdminRoleSeeder::class);

    /*
     * Pinned, because every task below is due at 09:00 with 60 minutes of
     * grace: run for real between 09:00 and 10:00 and the deadline is still in
     * the future, so "silently stopped" and "never ran at all" both report
     * healthy and their tests fail. Nothing was wrong with the code - the suite
     * simply disagreed with itself depending on the hour it was run.
     *
     * Noon is past the grace window and inside the same day, so the last due
     * time is 09:00 today for every test that does not travel somewhere else.
     */
    $this->travelTo(today()->setTime(12, 0));

    $this->scheduler = app(SchedulerHealthContract::class);

    $this->admin = AdminUser::factory()->create();
    $this->admin->assignRole('super-admin');

    $this->task = fn (array $attributes = []) => MonitoredScheduledTask::create(array_merge([
        'name' => 'trial-warnings',
        'type' => 'command',
        'cron_expression' => '0 9 * * *',
        'grace_time_in_minutes' => 60,
    ], $attributes));
});

it('calls a task healthy when it finished after it was last due', function () {
    ($this->task)(['last_finished_at' => now()->subMinutes(5)]);

    $overview = $this->scheduler->overview();

    expect($overview['tasks'][0]['is_healthy'])->toBeTrue()
        ->and($overview['unhealthy_count'])->toBe(0);
});

/*
 * The exact failure section 16 is worried about: nothing is erroring, the task
 * simply is not running any more.
 */
it('flags a task that has silently stopped running', function () {
    ($this->task)(['last_finished_at' => now()->subDays(3)]);

    $overview = $this->scheduler->overview();

    expect($overview['tasks'][0]['is_overdue'])->toBeTrue()
        ->and($overview['tasks'][0]['is_healthy'])->toBeFalse()
        ->and($overview['unhealthy_count'])->toBe(1);
});

it('flags a task that has never run at all', function () {
    ($this->task)(['last_finished_at' => null]);

    expect($this->scheduler->overview()['tasks'][0]['is_overdue'])->toBeTrue();
});

// Grace exists so a task that is merely late is not treated as broken.
it('does not flag a task still inside its grace window', function () {
    // Due at 09:00 daily with 60 minutes of grace; 09:30 today.
    $this->travelTo(today()->setTime(9, 30));

    ($this->task)(['last_finished_at' => today()->subDay()->setTime(9, 1)]);

    expect($this->scheduler->overview()['tasks'][0]['is_overdue'])->toBeFalse();
});

it('flags a task once its grace window has passed', function () {
    $this->travelTo(today()->setTime(10, 30));

    ($this->task)(['last_finished_at' => today()->subDay()->setTime(9, 1)]);

    expect($this->scheduler->overview()['tasks'][0]['is_overdue'])->toBeTrue();
});

// A task that succeeded once and fails now is not healthy.
it('flags a task whose most recent run failed', function () {
    ($this->task)([
        'last_finished_at' => now()->subMinutes(30),
        'last_failed_at' => now()->subMinutes(5),
    ]);

    $task = $this->scheduler->overview()['tasks'][0];

    expect($task['has_failed'])->toBeTrue()
        ->and($task['is_healthy'])->toBeFalse();
});

it('ignores an older failure that a later run recovered from', function () {
    ($this->task)([
        'last_failed_at' => now()->subHours(5),
        'last_finished_at' => now()->subMinutes(5),
    ]);

    expect($this->scheduler->overview()['tasks'][0]['has_failed'])->toBeFalse();
});

/*
 * "No tasks" and "all tasks green" render identically as a list, and mean very
 * different things: the first is a scheduler nobody ever synced.
 */
it('says when no task is registered at all', function () {
    $overview = $this->scheduler->overview();

    expect($overview['tasks'])->toBeEmpty()
        ->and($overview['is_registered'])->toBeFalse();
});

it('does not treat an unparseable cron expression as broken', function () {
    ($this->task)(['cron_expression' => 'not a cron', 'last_finished_at' => null]);

    expect($this->scheduler->overview()['tasks'][0]['is_overdue'])->toBeFalse();
});

it('shows the screen to staff', function () {
    ($this->task)(['last_finished_at' => now()]);

    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.scheduler.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/scheduler/index')
            ->has('tasks', 1)
            ->where('is_registered', true));
});

it('keeps customers out', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.scheduler.index'))
        ->assertRedirect(route('admin.login'));
});
