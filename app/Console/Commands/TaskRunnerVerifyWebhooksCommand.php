<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\TaskRunner\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Print signed TaskRunner webhook URLs for a task row and optionally POST from this machine.
 *
 * Remote servers must reach APP_URL over HTTPS (or HTTP); paste URLs into curl on the VM.
 */
class TaskRunnerVerifyWebhooksCommand extends Command
{
    protected $signature = 'dply:task-runner-verify-webhooks
                            {task_id? : ULID from task_runner_tasks.id}
                            {--urls-only : Only print signed URLs (no HTTP requests)}
                            {--ping-local : POST update-output and mark-as-finished from this host}';

    protected $description = 'Show TaskRunner signed webhook URLs and optionally verify them locally';

    public function handle(): int
    {
        $id = (string) ($this->argument('task_id') ?? '');
        if ($id === '') {
            $this->error('Pass a task ULID: php artisan dply:task-runner-verify-webhooks {task_id}');

            return self::FAILURE;
        }

        $task = Task::query()->find($id);
        if ($task === null) {
            $this->error("No task_runner_tasks row found for id: {$id}");

            return self::FAILURE;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $this->info('Control plane base: '.$appUrl);
        $this->line('Task: '.$task->name.' ('.$task->id.')');
        $this->newLine();

        $urls = [
            'update-output (append output / script_content)' => $task->webhookUrl('updateOutput'),
            'mark-as-finished' => $task->webhookUrl('markAsFinished'),
            'mark-as-failed' => $task->webhookUrl('markAsFailed'),
            'mark-as-timed-out' => $task->webhookUrl('markAsTimedOut'),
        ];

        foreach ($urls as $label => $url) {
            $this->line("<fg=cyan>{$label}</>");
            $this->line($url);
            $this->newLine();
        }

        $this->line('On the server (reachable to APP_URL), example:');
        $this->line('  curl -sS -X POST '.escapeshellarg($urls['update-output (append output / script_content)']).' \\');
        $this->line("    -H 'Content-Type: application/json' \\");
        $this->line("    -d '{\"output\":\"hello from $(hostname)\"}'");

        if ($this->option('urls-only')) {
            return self::SUCCESS;
        }

        if (! $this->option('ping-local')) {
            $this->newLine();
            $this->comment('Re-run with --ping-local to POST from this machine (uses APP_URL).');

            return self::SUCCESS;
        }

        $updateUrl = $urls['update-output (append output / script_content)'];
        $finishUrl = $urls['mark-as-finished'];

        $this->info('Pinging update-output…');
        $r1 = Http::acceptJson()->asJson()->post($updateUrl, [
            'output' => '[dply verify] ping from artisan at '.now()->toIso8601String().PHP_EOL,
        ]);
        if (! $r1->successful()) {
            $this->error('update-output failed: HTTP '.$r1->status().' '.$r1->body());

            return self::FAILURE;
        }
        $this->line($r1->body());

        $this->info('Pinging mark-as-finished…');
        $r2 = Http::acceptJson()->post($finishUrl, []);
        if (! $r2->successful()) {
            $this->error('mark-as-finished failed: HTTP '.$r2->status().' '.$r2->body());

            return self::FAILURE;
        }
        $this->line($r2->body());

        $this->info('Done. Refresh task row in the database / UI.');

        return self::SUCCESS;
    }
}
