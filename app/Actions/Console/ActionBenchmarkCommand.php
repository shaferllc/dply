<?php

declare(strict_types=1);

namespace App\Actions\Console;

use App\Actions\ActionBenchmark;
use Illuminate\Console\Command;

class ActionBenchmarkCommand extends Command
{
    protected $signature = 'actions:benchmark
                            {action : Action class to benchmark}
                            {--compare= : Compare with another action}
                            {--iterations=10 : Number of iterations}
                            {--detect-regression : Detect performance regression}';

    protected $description = 'Benchmark action performance';

    /**
     * Benchmark action performance.
     *
     * @example
     * // Benchmark an action
     * php artisan actions:benchmark App\Actions\ProcessOrder
     * @example
     * // Benchmark with more iterations
     * php artisan actions:benchmark App\Actions\ProcessOrder --iterations=50
     * @example
     * // Compare two actions
     * php artisan actions:benchmark App\Actions\ProcessOrderV1 --compare=App\Actions\ProcessOrderV2
     * @example
     * // Detect regression
     * php artisan actions:benchmark App\Actions\ProcessOrder --detect-regression
     */
    public function handle(): int
    {
        $action = $this->argument('action');
        $iterations = (int) $this->option('iterations');
        $compare = $this->option('compare');
        $detectRegression = $this->option('detect-regression');

        if ($compare) {
            $this->compareActions($action, $compare, $iterations);
        } elseif ($detectRegression) {
            $this->detectRegression($action, $iterations);
        } else {
            $this->benchmarkAction($action, $iterations);
        }

        return self::SUCCESS;
    }

    protected function benchmarkAction(string $actionClass, int $iterations): void
    {
        $this->info("Benchmarking: {$actionClass} ({$iterations} iterations)");
        $this->line('');

        $benchmark = ActionBenchmark::benchmark($actionClass, [], $iterations);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Iterations', $benchmark['iterations']],
                ['Avg Duration', number_format($benchmark['avg_duration_ms'], 2).' ms'],
                ['Min Duration', number_format($benchmark['min_duration_ms'], 2).' ms'],
                ['Max Duration', number_format($benchmark['max_duration_ms'], 2).' ms'],
                ['Avg Memory', number_format($benchmark['memory_avg_mb'], 2).' MB'],
            ]
        );
    }

    protected function compareActions(string $action1, string $action2, int $iterations): void
    {
        $this->info("Comparing: {$action1} vs {$action2}");
        $this->line('');

        $comparison = ActionBenchmark::compare($action1, $action2, [], $iterations);

        $this->table(
            ['Metric', $action1, $action2, 'Difference'],
            [
                ['Avg Duration (ms)', number_format($comparison['action1']['avg_duration_ms'], 2), number_format($comparison['action2']['avg_duration_ms'], 2), number_format($comparison['duration_diff_ms'], 2)],
                ['Memory (MB)', number_format($comparison['action1']['memory_avg_mb'], 2), number_format($comparison['action2']['memory_avg_mb'], 2), '-'],
            ]
        );

        $this->line('');
        $this->info("Faster: {$comparison['faster']}");
        $this->info("Slower: {$comparison['slower']}");
        $this->info('Performance Change: '.number_format($comparison['duration_percent_change'], 2).'%');
    }

    protected function detectRegression(string $actionClass, int $iterations): void
    {
        $this->info("Detecting regression: {$actionClass}");
        $this->line('');

        $regression = ActionBenchmark::detectRegression($actionClass, $iterations);

        if ($regression) {
            $this->warn('Performance regression detected!');
            $this->table(
                ['Metric', 'Baseline', 'Current', 'Change'],
                [
                    ['Avg Duration (ms)', number_format($regression['baseline']['avg_duration_ms'], 2), number_format($regression['current']['avg_duration_ms'], 2), number_format($regression['degradation_percent'], 2).'%'],
                ]
            );
        } else {
            $this->info('No performance regression detected.');
        }
    }
}
