<?php

declare(strict_types=1);

namespace App\Actions\Console;

use App\Actions\ActionErrorTracking;
use Illuminate\Console\Command;

class ActionErrorsCommand extends Command
{
    protected $signature = 'actions:errors
                            {--action= : Show errors for specific action}
                            {--clear : Clear errors}';

    protected $description = 'View action error tracking';

    /**
     * View action error tracking.
     *
     * @example
     * // Show dashboard
     * php artisan actions:errors
     * @example
     * // Show specific action
     * php artisan actions:errors --action=App\Actions\ProcessOrder
     * @example
     * // Clear errors
     * php artisan actions:errors --action=App\Actions\ProcessOrder --clear
     */
    public function handle(): int
    {
        if ($this->option('clear') && $action = $this->option('action')) {
            ActionErrorTracking::clearErrors($action);
            $this->info("Errors cleared for: {$action}");

            return Command::SUCCESS;
        }

        if ($action = $this->option('action')) {
            $this->showAction($action);
        } else {
            $this->showDashboard();
        }

        return Command::SUCCESS;
    }

    protected function showDashboard(): void
    {
        $dashboard = ActionErrorTracking::dashboard();

        $this->info('Error Tracking Dashboard');
        $this->line('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Errors', number_format($dashboard['total_errors'])],
                ['Actions with Errors', $dashboard['actions_with_errors']],
            ]
        );

        if (! empty($dashboard['actions'])) {
            $this->line('');
            $this->info('Actions with Errors:');
            $this->table(
                ['Action', 'Total Errors', 'Unique Errors'],
                collect($dashboard['actions'])->take(20)->map(fn ($a) => [
                    class_basename($a['action']),
                    number_format($a['total_errors']),
                    number_format($a['unique_errors']),
                ])->toArray()
            );
        }
    }

    protected function showAction(string $actionClass): void
    {
        $summary = ActionErrorTracking::getSummary($actionClass);

        $this->info("Error Summary: {$actionClass}");
        $this->line('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Errors', number_format($summary['total_errors'])],
                ['Unique Errors', number_format($summary['unique_errors'])],
            ]
        );

        if (! empty($summary['most_common'])) {
            $this->line('');
            $this->warn('Most Common Errors:');
            $this->table(
                ['Exception', 'Message', 'Count'],
                collect($summary['most_common'])->map(fn ($e) => [
                    class_basename($e['exception']),
                    substr($e['message'], 0, 50),
                    number_format($e['count']),
                ])->toArray()
            );
        }
    }
}
