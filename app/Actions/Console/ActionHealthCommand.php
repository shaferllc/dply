<?php

declare(strict_types=1);

namespace App\Actions\Console;

use App\Actions\ActionHealth;
use Illuminate\Console\Command;

class ActionHealthCommand extends Command
{
    protected $signature = 'actions:health
                            {--action= : Check health for specific action}
                            {--unhealthy : Show only unhealthy actions}';

    protected $description = 'Check action health status';

    /**
     * Check action health status.
     *
     * @example
     * // Check system health overview
     * php artisan actions:health
     * @example
     * // Check health for specific action
     * php artisan actions:health --action=App\Actions\ProcessOrder
     * @example
     * // Show only unhealthy actions
     * php artisan actions:health --unhealthy
     */
    public function handle(): int
    {
        if ($action = $this->option('action')) {
            $this->checkAction($action);
        } elseif ($this->option('unhealthy')) {
            $this->showUnhealthy();
        } else {
            $this->showSystemHealth();
        }

        return Command::SUCCESS;
    }

    protected function checkAction(string $actionClass): void
    {
        $health = ActionHealth::check($actionClass);

        $status = $health['healthy'] ? '<info>✓ Healthy</info>' : '<error>✗ Unhealthy</error>';

        $this->info("Health Status for: {$actionClass}");
        $this->line("Status: {$status}");

        if (! empty($health['issues'])) {
            $this->line('');
            $this->warn('Issues:');
            foreach ($health['issues'] as $issue) {
                $this->line("  - {$issue}");
            }
        }

        $this->line('');
        $this->line('Metrics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Calls', number_format($health['metrics']['calls'] ?? 0)],
                ['Successes', number_format($health['metrics']['successes'] ?? 0)],
                ['Failures', number_format($health['metrics']['failures'] ?? 0)],
                ['Success Rate', number_format(($health['metrics']['success_rate'] ?? 0) * 100, 2).'%'],
                ['Avg Duration', number_format($health['metrics']['avg_duration_ms'] ?? 0, 2).' ms'],
            ]
        );
    }

    protected function showUnhealthy(): void
    {
        $unhealthy = ActionHealth::getUnhealthyActions();

        if ($unhealthy->isEmpty()) {
            $this->info('All actions are healthy!');

            return;
        }

        $this->warn('Unhealthy Actions:');
        $this->table(
            ['Action', 'Status', 'Issues'],
            $unhealthy->map(fn ($h) => [
                class_basename($h['action']),
                $h['status'],
                implode(', ', $h['issues']),
            ])->toArray()
        );
    }

    protected function showSystemHealth(): void
    {
        $systemHealth = ActionHealth::getSystemHealth();

        $status = $systemHealth['overall'] === 'healthy' ? '<info>✓ Healthy</info>' : '<warning>⚠ Degraded</warning>';

        $this->info('System Health');
        $this->line("Overall Status: {$status}");
        $this->line('');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Actions', $systemHealth['total_actions']],
                ['Healthy Actions', $systemHealth['healthy_actions']],
                ['Unhealthy Actions', $systemHealth['unhealthy_actions']],
                ['Health Percentage', number_format($systemHealth['health_percentage'], 2).'%'],
            ]
        );
    }
}
