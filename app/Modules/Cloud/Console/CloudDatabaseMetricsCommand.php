<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Modules\Cloud\Console\Concerns\ResolvesManagedDatabase;
use App\Modules\Database\Services\ManagedDatabaseMetrics;
use Illuminate\Console\Command;

/**
 * Cluster metrics for a managed database.
 *
 *   dply:cloud:db:metrics <database> [window] [--json]
 *
 * The table prints min / avg / max over the window rather than a sparkline —
 * the detail page draws the shape; the CLI answers "is it hot".
 */
class CloudDatabaseMetricsCommand extends Command
{
    use ResolvesManagedDatabase;

    protected $signature = 'dply:cloud:db:metrics
        {database : Managed database ID or name}
        {window=24h : One of 1h, 24h, 7d}
        {--json : Output as JSON}';

    protected $description = 'Print CPU / memory / disk metrics for a managed database.';

    public function handle(ManagedDatabaseMetrics $metrics): int
    {
        $needle = (string) $this->argument('database');
        $database = $this->resolveManagedDatabase($needle);
        if ($database === null) {
            $this->error("Managed database not found: {$needle}");

            return self::FAILURE;
        }

        if (! $metrics->supports($database)) {
            $this->error("No metrics available for \"{$database->name}\" — its backend does not report them to dply.");

            return self::FAILURE;
        }

        $window = strtolower((string) $this->argument('window'));
        if (! in_array($window, $metrics->windows(), true)) {
            $this->error('Unknown window. Use one of: '.implode(', ', $metrics->windows()));

            return self::FAILURE;
        }

        $charts = $metrics->forWindow($database, $window);

        $rows = [];
        foreach ($charts as $chart) {
            $series = $chart['series'];
            if ($series === []) {
                $rows[] = [$chart['label'], '—', '—', '—', '0'];

                continue;
            }

            $rows[] = [
                $chart['label'],
                number_format(min(array_column($series, 'min')), 2),
                number_format(array_sum(array_column($series, 'avg')) / count($series), 2),
                number_format(max(array_column($series, 'max')), 2),
                (string) count($series),
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['window' => $window, 'metrics' => $charts], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("<fg=cyan>{$database->name}</> — last {$window}");
        $this->newLine();
        $this->table(['metric', 'min', 'avg', 'max', 'points'], $rows);

        return self::SUCCESS;
    }
}
