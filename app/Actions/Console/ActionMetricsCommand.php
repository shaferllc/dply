<?php

declare(strict_types=1);

namespace App\Actions\Console;

use App\Actions\ActionMetrics;
use Illuminate\Console\Command;

class ActionMetricsCommand extends Command
{
    protected $signature = 'actions:metrics
                            {--action= : Show metrics for specific action}
                            {--slowest : Show slowest actions}
                            {--most-called : Show most called actions}
                            {--failures : Show actions with highest failure rate}';

    protected $description = 'Display action performance metrics';

    /**
     * Display action performance metrics.
     *
     * @example
     * // Show dashboard with all metrics
     * php artisan actions:metrics
     * @example
     * // Show metrics for specific action
     * php artisan actions:metrics --action=App\Actions\ProcessOrder
     * @example
     * // Show slowest actions
     * php artisan actions:metrics --slowest
     * @example
     * // Show most called actions
     * php artisan actions:metrics --most-called
     * @example
     * // Show actions with highest failure rate
     * php artisan actions:metrics --failures
     */
    public function handle(): int
    {
        if ($this->option('slowest')) {
            $this->showSlowest();
        } elseif ($this->option('most-called')) {
            $this->showMostCalled();
        } elseif ($this->option('failures')) {
            $this->showFailures();
        } elseif ($action = $this->option('action')) {
            $this->showAction($action);
        } else {
            $this->showDashboard();
        }

        return Command::SUCCESS;
    }

    protected function showDashboard(): void
    {
        $dashboard = ActionMetrics::dashboard();

        $this->info('Action Metrics Dashboard');
        $this->line('');
        $this->line('Summary:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Actions', $dashboard['summary']['total_actions']],
                ['Active Actions', $dashboard['summary']['active_actions']],
                ['Total Calls', number_format($dashboard['summary']['total_calls'])],
                ['Total Successes', number_format($dashboard['summary']['total_successes'])],
                ['Total Failures', number_format($dashboard['summary']['total_failures'])],
                ['Success Rate', number_format($dashboard['summary']['overall_success_rate'] * 100, 2).'%'],
                ['Avg Duration', number_format($dashboard['summary']['avg_duration_ms'], 2).' ms'],
            ]
        );

        if (! empty($dashboard['slowest'])) {
            $this->line('');
            $this->info('Slowest Actions:');
            $this->table(
                ['Action', 'Avg Duration (ms)', 'Calls'],
                collect($dashboard['slowest'])->map(fn ($a) => [
                    class_basename($a['action']),
                    number_format($a['avg_duration_ms'], 2),
                    number_format($a['calls']),
                ])->toArray()
            );
        }

        if (! empty($dashboard['most_called'])) {
            $this->line('');
            $this->info('Most Called Actions:');
            $this->table(
                ['Action', 'Calls', 'Avg Duration (ms)'],
                collect($dashboard['most_called'])->map(fn ($a) => [
                    class_basename($a['action']),
                    number_format($a['calls']),
                    number_format($a['avg_duration_ms'], 2),
                ])->toArray()
            );
        }
    }

    protected function showAction(string $actionClass): void
    {
        $metrics = ActionMetrics::getMetrics($actionClass);

        $this->info("Metrics for: {$actionClass}");
        $this->line('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Calls', number_format($metrics['calls'])],
                ['Successes', number_format($metrics['successes'])],
                ['Failures', number_format($metrics['failures'])],
                ['Success Rate', number_format($metrics['success_rate'] * 100, 2).'%'],
                ['Avg Duration', number_format($metrics['avg_duration_ms'], 2).' ms'],
                ['Min Duration', $metrics['min_duration_ms'] ? number_format($metrics['min_duration_ms'], 2).' ms' : 'N/A'],
                ['Max Duration', $metrics['max_duration_ms'] ? number_format($metrics['max_duration_ms'], 2).' ms' : 'N/A'],
                ['Avg Memory', number_format($metrics['avg_memory_mb'], 2).' MB'],
            ]
        );
    }

    protected function showSlowest(): void
    {
        $slowest = ActionMetrics::getSlowestActions(10);

        $this->info('Slowest Actions:');
        $this->table(
            ['Action', 'Avg Duration (ms)', 'Calls'],
            $slowest->map(fn ($a) => [
                class_basename($a['action']),
                number_format($a['avg_duration_ms'], 2),
                number_format($a['calls']),
            ])->toArray()
        );
    }

    protected function showMostCalled(): void
    {
        $mostCalled = ActionMetrics::getMostCalledActions(10);

        $this->info('Most Called Actions:');
        $this->table(
            ['Action', 'Calls', 'Avg Duration (ms)'],
            $mostCalled->map(fn ($a) => [
                class_basename($a['action']),
                number_format($a['calls']),
                number_format($a['avg_duration_ms'], 2),
            ])->toArray()
        );
    }

    protected function showFailures(): void
    {
        $failures = ActionMetrics::getHighestFailureRate(10);

        $this->info('Actions with Highest Failure Rate:');
        $this->table(
            ['Action', 'Failure Rate', 'Failures', 'Total Calls'],
            $failures->map(fn ($a) => [
                class_basename($a['action']),
                number_format($a['failure_rate'] * 100, 2).'%',
                number_format($a['failures']),
                number_format($a['calls']),
            ])->toArray()
        );
    }
}
