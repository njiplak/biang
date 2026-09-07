<?php

namespace App\Service\Admin;

use App\Contract\Admin\SchedulerHealthContract;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;
use Throwable;

/**
 * Section 16: "Trial-ending emails are a launch blocker, not a nice-to-have."
 *
 * bootstrap/app.php already monitors all four scheduled tasks, and the reason
 * it does is written there: "a scheduler that silently stops looks exactly like
 * a scheduler with nothing to do". Recording that was only half the job - until
 * this screen, nobody could see it.
 */
class SchedulerHealthService implements SchedulerHealthContract
{
    public function overview(): array
    {
        $tasks = MonitoredScheduledTask::query()
            ->orderBy('name')
            ->get()
            ->map(fn (MonitoredScheduledTask $task) => $this->present($task))
            ->all();

        return [
            'tasks' => $tasks,
            // The two states that are NOT "one task is unhealthy", and which
            // look identical from a list of green rows.
            'is_registered' => $tasks !== [],
            'unhealthy_count' => count(array_filter($tasks, fn (array $t) => ! $t['is_healthy'])),
        ];
    }

    private function present(MonitoredScheduledTask $task): array
    {
        $due = $this->lastDueAt($task);
        $overdue = $this->isOverdue($task, $due);

        return [
            'id' => $task->id,
            'name' => $task->name,
            'cron_expression' => $task->cron_expression,
            'grace_time_in_minutes' => $task->grace_time_in_minutes,
            'last_started_at' => $task->last_started_at,
            'last_finished_at' => $task->last_finished_at,
            'last_failed_at' => $task->last_failed_at,
            'last_skipped_at' => $task->last_skipped_at,
            'expected_by' => $due,
            'is_overdue' => $overdue,
            // A task that failed most recently is unhealthy even if it once
            // succeeded, which is why this is not just "did it run".
            'has_failed' => $task->last_failed_at !== null
                && ($task->last_finished_at === null || $task->last_failed_at->gt($task->last_finished_at)),
            'is_healthy' => ! $overdue
                && ! ($task->last_failed_at !== null
                    && ($task->last_finished_at === null || $task->last_failed_at->gt($task->last_finished_at))),
        ];
    }

    /**
     * When this task was last SUPPOSED to have run, from its own cron
     * expression. Comparing against that rather than a fixed window is what
     * makes an hourly task and a daily one both answerable.
     */
    private function lastDueAt(MonitoredScheduledTask $task): ?Carbon
    {
        try {
            return Carbon::instance(
                (new CronExpression($task->cron_expression))->getPreviousRunDate(now())
            );
        } catch (Throwable) {
            // An expression we cannot parse is not evidence of a healthy task,
            // but it is not evidence of a broken one either - say nothing.
            return null;
        }
    }

    private function isOverdue(MonitoredScheduledTask $task, ?Carbon $due): bool
    {
        if ($due === null) {
            return false;
        }

        $deadline = $due->copy()->addMinutes($task->grace_time_in_minutes);

        if ($deadline->isFuture()) {
            return false;
        }

        // Never having finished at all counts as overdue once the first
        // deadline passes - that is exactly the "silently stopped" case.
        return $task->last_finished_at === null
            || $task->last_finished_at->lt($due);
    }
}
