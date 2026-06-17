<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use App\Services\Cloud\ResolvesMetricWindows;
use Illuminate\Console\Command;

/**
 * Print CPU / memory / restart metric series for a cloud container site.
 *
 *   dply:cloud:metrics <site> [--window=1h] [--json]
 *
 * Backend behavior:
 *   - DigitalOcean App Platform: live-fetches the monitoring API
 *     (/v2/monitoring/metrics/apps/{cpu,memory,restart}) — 60s cached.
 *   - AWS App Runner: returns the unavailable state plus a CloudWatch
 *     console deep link (CloudWatch holds App Runner metrics).
 *   - FakeCloudBackend: returns deterministic synthetic series.
 */
class CloudMetricsCommand extends Command
{
    protected $signature = 'dply:cloud:metrics
        {site : Site ID, slug, or name}
        {--window=1h : Metric window — 1h, 6h, or 24h}
        {--json : Output as JSON}';

    protected $description = 'Print CPU / memory / restart metrics for a cloud container site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if ($site->container_backend === '') {
            $this->error("Site {$site->name} is not a cloud container site.");

            return self::FAILURE;
        }

        $windowRaw = (string) ($this->option('window') ?: '1h');
        $window = in_array($windowRaw, ResolvesMetricWindows::metricWindows(), true) ? $windowRaw : '1h';

        $backend = CloudRouter::backendFor($site);
        $credential = CloudRouter::credentialFor($site);
        if ($backend === null || $credential === null) {
            $this->error('No backend or credential resolvable for this site.');

            return self::FAILURE;
        }

        try {
            $metrics = $backend->metrics($site, $credential, $window);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch metrics: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'site' => $site->name,
                'metrics' => $metrics,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Metrics for</> '.$site->name.' <fg=gray>(window: '.$metrics['window'].')</>');

        if (! $metrics['available']) {
            $this->newLine();
            if (isset($metrics['note']) && $metrics['note'] !== '') {
                $this->line('<fg=yellow>'.$metrics['note'].'</>');
            } else {
                $this->line('<fg=yellow>Metrics are not available for this site.</>');
            }
            if (isset($metrics['url']) && $metrics['url'] !== '') {
                $this->newLine();
                $this->line('View in console:');
                $this->line($metrics['url']);
            }

            return self::SUCCESS;
        }

        $series = $metrics['series'];
        if ($series === []) {
            $this->newLine();
            $this->line('<fg=gray>No metric series returned.</>');

            return self::SUCCESS;
        }

        foreach ($series as $name => $points) {
            $values = array_map(static fn (array $p): float => (float) $p['v'], $points);
            $this->newLine();
            $this->line('<fg=green>'.strtoupper((string) $name).'</> — '.count($points).' point(s)');
            if ($values !== []) {
                $this->line(sprintf(
                    '  min %.2f · avg %.2f · max %.2f · latest %.2f',
                    min($values),
                    array_sum($values) / count($values),
                    max($values),
                    (float) end($values),
                ));
            } else {
                $this->line('  <fg=gray>(no data)</>');
            }
        }

        return self::SUCCESS;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
