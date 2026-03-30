<?php

declare(strict_types=1);

namespace App\Actions\Console;

use App\Actions\ActionRetry;
use Illuminate\Console\Command;

class ActionRetryCommand extends Command
{
    protected $signature = 'actions:retry
                            {--action= : Show retry stats for specific action}
                            {--high : Show actions with high retry rates}
                            {--clear : Clear retry statistics}';

    protected $description = 'Monitor retry attempts and failures';

    /**
     * Monitor retry attempts and failures.
     *
     * @example
     * // Show dashboard
     * php artisan actions:retry
     * @example
     * // Show specific action
     * php artisan actions:retry --action=App\Actions\ProcessOrder
     * @example
     * // Show high retry rates
     * php artisan actions:retry --high
     * @example
     * // Clear statistics
     * php artisan actions:retry --action=App\Actions\ProcessOrder --clear
     */
    public function handle(): int
    {
        if ($this->option('clear') && $action = $this->option('action')) {
            ActionRetry::clearStats($action);
            $this->info("Retry statistics cleared for: {$action}");

            return Command::SUCCESS;
        }

        if ($this->option('high')) {
            $this->showHighRetry();
        } elseif ($action = $this->option('action')) {
            $this->showAction($action);
        } else {
            $this->showDashboard();
        }

        return Command::SUCCESS;
    }

    protected function showDashboard(): void
    {
        $dashboard = ActionRetry::dashboard();

        $this->info('Retry Dashboard');
        $this->line('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Retry Actions', $dashboard['total_retry_actions']],
                ['High Retry Rate', $dashboard['high_retry_rate']],
            ]
        );

        if (! empty($dashboard['actions'])) {
            $this->line('');
            $this->info('Retry Statistics:');
            $this->table(
                ['Action', 'Total Attempts', 'Avg Retries', 'Success Rate'],
                collect($dashboard['actions'])->map(fn ($a) => [
                    class_basename($a['action']),
                    number_format($a['total_attempts']),
                    number_format($a['avg_retries'], 2),
                    number_format($a['success_rate'] * 100, 2).'%',
                ])->toArray()
            );
        }
    }

    protected function showAction(string $actionClass): void
    {
        $stats = ActionRetry::getStats($actionClass);

        $this->info("Retry Statistics: {$actionClass}");
        $this->line('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Attempts', number_format($stats['total_attempts'])],
                ['Successful Attempts', number_format($stats['successful_attempts'])],
                ['Failed Attempts', number_format($stats['failed_attempts'])],
                ['Retry Count', number_format($stats['retry_count'])],
                ['Success Rate', number_format($stats['success_rate'] * 100, 2).'%'],
                ['Avg Retries', number_format($stats['avg_retries'], 2)],
            ]
        );
    }

    protected function showHighRetry(): void
    {
        $high = ActionRetry::getHighRetryRate(10);

        if ($high->isEmpty()) {
            $this->info('No actions with high retry rates.');

            return;
        }

        $this->warn('Actions with High Retry Rates:');
        $this->table(
            ['Action', 'Avg Retries', 'Total Attempts', 'Success Rate'],
            $high->map(fn ($a) => [
                class_basename($a['action']),
                number_format($a['avg_retries'], 2),
                number_format($a['total_attempts']),
                number_format($a['success_rate'] * 100, 2).'%',
            ])->toArray()
        );
    }
}
