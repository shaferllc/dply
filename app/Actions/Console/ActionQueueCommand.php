<?php

declare(strict_types=1);

namespace App\Actions\Console;

use App\Actions\ActionQueue;
use App\Actions\ActionRegistry;
use App\Actions\Concerns\AsJob;
use Illuminate\Console\Command;

class ActionQueueCommand extends Command
{
    protected $signature = 'actions:queue
                            {--action= : Show queue status for specific action}
                            {--failed : Show failed jobs}
                            {--retry= : Retry failed job by ID}
                            {--clear : Clear failed jobs}';

    protected $description = 'Monitor queued actions';

    /**
     * Monitor queued actions.
     *
     * @example
     * // Show dashboard
     * php artisan actions:queue
     * @example
     * // Show specific action
     * php artisan actions:queue --action=App\Actions\ProcessOrder
     * @example
     * // Show failed jobs
     * php artisan actions:queue --failed
     * @example
     * // Retry failed job
     * php artisan actions:queue --retry=123
     * @example
     * // Clear failed jobs
     * php artisan actions:queue --action=App\Actions\ProcessOrder --clear
     */
    public function handle(): int
    {
        if ($this->option('retry')) {
            $jobId = (int) $this->option('retry');
            ActionQueue::retryFailedJob($jobId);
            $this->info("Retrying failed job: {$jobId}");

            return Command::SUCCESS;
        }

        if ($this->option('clear') && $action = $this->option('action')) {
            ActionQueue::clearFailedJobs($action);
            $this->info("Failed jobs cleared for: {$action}");

            return Command::SUCCESS;
        }

        if ($this->option('failed')) {
            $this->showFailed();
        } elseif ($action = $this->option('action')) {
            $this->showAction($action);
        } else {
            $this->showDashboard();
        }

        return Command::SUCCESS;
    }

    protected function showDashboard(): void
    {
        $dashboard = ActionQueue::dashboard();

        $this->info('Queue Dashboard');
        $this->line('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Queued', number_format($dashboard['total_queued'])],
                ['Total Processed', number_format($dashboard['total_processed'])],
                ['Total Failed', number_format($dashboard['total_failed'])],
            ]
        );

        if (! empty($dashboard['actions'])) {
            $this->line('');
            $this->info('Queue Status by Action:');
            $this->table(
                ['Action', 'Queued', 'Processed', 'Failed', 'Pending'],
                collect($dashboard['actions'])->map(fn ($a) => [
                    class_basename($a['action']),
                    number_format($a['queued']),
                    number_format($a['processed']),
                    number_format($a['failed']),
                    number_format($a['pending']),
                ])->toArray()
            );
        }
    }

    protected function showAction(string $actionClass): void
    {
        $status = ActionQueue::getStatus($actionClass);

        $this->info("Queue Status: {$actionClass}");
        $this->line('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Queued', number_format($status['queued'])],
                ['Processed', number_format($status['processed'])],
                ['Failed', number_format($status['failed'])],
                ['Pending', number_format($status['pending'])],
            ]
        );
    }

    protected function showFailed(): void
    {
        $actions = ActionRegistry::getByTrait(AsJob::class);
        $allFailed = collect();

        foreach ($actions as $actionClass) {
            $failed = ActionQueue::getFailedJobs($actionClass);
            $allFailed = $allFailed->merge($failed);
        }

        if ($allFailed->isEmpty()) {
            $this->info('No failed jobs.');

            return;
        }

        $this->warn('Failed Jobs:');
        $this->table(
            ['ID', 'Action', 'Failed At'],
            $allFailed->take(20)->map(fn ($job) => [
                $job['id'],
                class_basename($job['action']),
                $job['failed_at'],
            ])->toArray()
        );
    }
}
