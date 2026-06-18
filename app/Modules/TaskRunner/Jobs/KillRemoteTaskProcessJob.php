<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Jobs;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\TaskDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort remote process kill for a task that has already been marked Cancelled locally.
 * Runs in a queue worker so the Livewire/HTTP request that initiated the cancel can return
 * immediately without blocking on SSH (which can sit waiting on stdio for tens of seconds).
 */
class KillRemoteTaskProcessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public string $taskId,
        public string $script,
    ) {}

    public function handle(TaskDispatcher $dispatcher): void
    {
        $task = Task::find($this->taskId);
        if (! $task || ! $task->server) {
            return;
        }

        $cancelTask = AnonymousTask::command('Cancel Task Process', $this->script);
        $cancelTask->setTimeout(45);

        try {
            $output = $dispatcher->run(
                $cancelTask->pending()
                    ->onConnection($task->server->connectionAsRoot())
                    ->timeout(45)
            );

            if (! $output || ! $output->isSuccessful()) {
                Log::warning('Remote task process kill did not confirm success.', [
                    'task_id' => $task->id,
                    'exit_code' => $output?->getExitCode(),
                    'timed_out' => $output?->isTimeout(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Remote task process kill SSH errored.', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
