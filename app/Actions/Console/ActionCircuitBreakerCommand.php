<?php

declare(strict_types=1);

namespace App\Actions\Console;

use App\Actions\ActionCircuitBreaker;
use Illuminate\Console\Command;

class ActionCircuitBreakerCommand extends Command
{
    protected $signature = 'actions:circuit-breaker
                            {--action= : Show circuit breaker for specific action}
                            {--open : Show only open circuits}
                            {--reset : Reset circuit breaker}';

    protected $description = 'Monitor circuit breaker states';

    /**
     * Monitor circuit breaker states.
     *
     * @example
     * // Show dashboard
     * php artisan actions:circuit-breaker
     * @example
     * // Show specific action
     * php artisan actions:circuit-breaker --action=App\Actions\ProcessOrder
     * @example
     * // Show only open circuits
     * php artisan actions:circuit-breaker --open
     * @example
     * // Reset circuit breaker
     * php artisan actions:circuit-breaker --action=App\Actions\ProcessOrder --reset
     */
    public function handle(): int
    {
        if ($this->option('reset') && $action = $this->option('action')) {
            ActionCircuitBreaker::reset($action);
            $this->info("Circuit breaker reset for: {$action}");

            return self::SUCCESS;
        }

        if ($this->option('open')) {
            $this->showOpen();
        } elseif ($action = $this->option('action')) {
            $this->showAction($action);
        } else {
            $this->showDashboard();
        }

        return self::SUCCESS;
    }

    protected function showDashboard(): void
    {
        $dashboard = ActionCircuitBreaker::dashboard();

        $this->info('Circuit Breaker Dashboard');
        $this->line('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Circuits', $dashboard['total_circuits']],
                ['Open Circuits', $dashboard['open_circuits']],
                ['Half-Open Circuits', $dashboard['half_open_circuits']],
                ['Closed Circuits', $dashboard['closed_circuits']],
            ]
        );

        if (! empty($dashboard['circuits'])) {
            $this->line('');
            $this->info('Circuit States:');
            $this->table(
                ['Action', 'State', 'Failures', 'Threshold', 'Timeout'],
                collect($dashboard['circuits'])->map(fn ($c) => [
                    class_basename($c['action']),
                    $c['state'],
                    $c['failures'],
                    $c['threshold'],
                    $c['timeout'].'s',
                ])->toArray()
            );
        }
    }

    protected function showAction(string $actionClass): void
    {
        $status = ActionCircuitBreaker::getStatus($actionClass);

        $stateColor = match ($status['state']) {
            'open' => 'error',
            'half-open' => 'warning',
            'closed' => 'info',
            default => 'info',
        };

        $this->info("Circuit Breaker Status: {$actionClass}");
        $this->line('');
        $this->table(
            ['Property', 'Value'],
            [
                ['State', "<{$stateColor}>{$status['state']}</{$stateColor}>"],
                ['Failures', $status['failures']],
                ['Threshold', $status['threshold']],
                ['Timeout', $status['timeout'].'s'],
                ['Last Failure', $status['last_failure'] ?? 'Never'],
            ]
        );
    }

    protected function showOpen(): void
    {
        $open = ActionCircuitBreaker::getOpenCircuits();

        if ($open->isEmpty()) {
            $this->info('No open circuit breakers.');

            return;
        }

        $this->warn('Open Circuit Breakers:');
        $this->table(
            ['Action', 'Failures', 'Threshold', 'Last Failure'],
            $open->map(fn ($c) => [
                class_basename($c['action']),
                $c['failures'],
                $c['threshold'],
                $c['last_failure'] ?? 'Never',
            ])->toArray()
        );
    }
}
