<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backstop for TaskRunner tasks that go silent.
 *
 * A remote task reports its outcome by POSTing lifecycle webhooks back to the
 * control plane. If those callbacks never land — a rejected webhook, a box that
 * OOMs/reboots, a dropped network — the Task sits in `running` forever, the
 * server stays `setup_status=running`, and the provision journey spins with no
 * error and no Resume action. There was previously NO server-side safety net:
 * TaskTimeoutJob is a single delayed job (and wasn't even dispatched for
 * background provision tasks), and nothing swept stale rows.
 *
 * This command is that net. It runs every minute and fails any `running` task
 * that has either blown past its own timeout or gone quiet (no output/heartbeat)
 * for longer than the configured window. Because it updates each task via
 * Eloquent (not a bulk query), {@see \App\Observers\TaskRunnerTaskObserver}
 * still fires on the status change and flips the owning server to
 * `setup_status=FAILED`, which surfaces the failure + Resume in the journey.
 */
class SweepStalledTasksCommand extends Command
{
    protected $signature = 'dply:tasks:sweep-stalled {--dry-run : Report what would be failed without changing anything}';

    protected $description = 'Fail TaskRunner tasks stuck in running past their timeout or with no heartbeat, so wedged provisions surface and recover.';

    public function handle(): int
    {
        // No output for this long while still "running" = the remote process is
        // almost certainly dead (provision steps stream output continuously).
        $heartbeatSeconds = max(60, (int) config('task-runner.stall.heartbeat_seconds', 600));
        // Slack added on top of a task's own declared timeout before we force it.
        $graceSeconds = max(0, (int) config('task-runner.stall.grace_seconds', 120));

        $now = now();
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Task::query()
            ->where('status', TaskStatus::Running)
            ->whereNotNull('started_at')
            ->get();

        $swept = 0;

        foreach ($candidates as $task) {
            try {
                $reason = $this->stallReason($task, $now->copy(), $heartbeatSeconds, $graceSeconds);
                if ($reason === null) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("[dry-run] would fail task {$task->id} ({$task->action}): {$reason}");

                    continue;
                }

                $existing = (string) ($task->output ?? '');

                $task->update([
                    'status' => TaskStatus::Timeout,
                    'completed_at' => $now,
                    'output' => $existing.($existing !== '' && ! str_ends_with($existing, "\n") ? "\n" : '')
                        ."\n[dply-stalled] {$reason} — failed by the stalled-task sweeper.",
                ]);

                $swept++;

                Log::warning('task-runner.stalled_task.swept', [
                    'task_id' => $task->id,
                    'action' => $task->action,
                    'server_id' => $task->server_id,
                    'reason' => $reason,
                ]);
            } catch (\Throwable $e) {
                // One bad row must not abort the sweep.
                Log::error('task-runner.stalled_task.sweep_failed', [
                    'task_id' => $task->id ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->info($dryRun
            ? "Checked {$candidates->count()} running task(s)."
            : "Checked {$candidates->count()} running task(s); failed {$swept} stalled.");

        return self::SUCCESS;
    }

    /**
     * Return a human-readable stall reason, or null if the task is healthy.
     */
    private function stallReason(Task $task, \Illuminate\Support\Carbon $now, int $heartbeatSeconds, int $graceSeconds): ?string
    {
        $startedAt = $task->started_at;
        if ($startedAt === null) {
            return null;
        }

        $timeout = (int) ($task->timeout ?: 3600);
        $sinceStart = (int) abs($now->diffInSeconds($startedAt, true));
        $sinceUpdate = (int) abs($now->diffInSeconds($task->updated_at ?? $startedAt, true));

        if ($sinceStart >= $timeout + $graceSeconds) {
            return "running for {$sinceStart}s with no completion callback (timeout {$timeout}s)";
        }

        if ($sinceUpdate >= $heartbeatSeconds) {
            return "no output/heartbeat for {$sinceUpdate}s (threshold {$heartbeatSeconds}s)";
        }

        return null;
    }
}
