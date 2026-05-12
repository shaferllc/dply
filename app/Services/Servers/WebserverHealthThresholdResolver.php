<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\WebserverHealthThreshold;

/**
 * Resolves the effective alert threshold for a (server, engine, metric)
 * triple. Resolution precedence (most specific wins):
 *
 *   1. (server_id, engine, metric)
 *   2. (server_id, NULL,   metric)
 *   3. (organization_id, engine, metric)
 *   4. (organization_id, NULL,   metric)
 *   5. config/server_metrics.php → health_thresholds.{metric}
 *
 * Returns null when neither overrides nor a config fallback exist —
 * which means "no threshold defined for this metric, skip alerting on it."
 */
class WebserverHealthThresholdResolver
{
    /**
     * @return array{comparator: string, value: float, severity: string}|null
     */
    public function resolve(Server $server, string $engine, string $metric): ?array
    {
        // 1 + 2: server-scoped overrides. Most specific (engine match) first.
        // NB: `WHERE engine IN (..., NULL)` doesn't match NULL in SQL —
        // an explicit OR-IS-NULL is required.
        $serverRows = WebserverHealthThreshold::query()
            ->where('server_id', $server->id)
            ->where('metric', $metric)
            ->where(function ($q) use ($engine): void {
                $q->where('engine', $engine)->orWhereNull('engine');
            })
            ->get();
        $row = $this->pickEngineSpecificFirst($serverRows, $engine);
        if ($row !== null) {
            return $this->toArray($row);
        }

        // 3 + 4: org-scoped defaults.
        $orgId = $server->organization_id;
        if ($orgId !== null) {
            $orgRows = WebserverHealthThreshold::query()
                ->where('organization_id', $orgId)
                ->whereNull('server_id')
                ->where('metric', $metric)
                ->where(function ($q) use ($engine): void {
                    $q->where('engine', $engine)->orWhereNull('engine');
                })
                ->get();
            $row = $this->pickEngineSpecificFirst($orgRows, $engine);
            if ($row !== null) {
                return $this->toArray($row);
            }
        }

        // 5: hardcoded fallback.
        $fallback = (array) config('server_metrics.health_thresholds.'.$metric, []);
        if ($fallback === [] || ! isset($fallback['comparator'], $fallback['value'])) {
            return null;
        }

        return [
            'comparator' => (string) $fallback['comparator'],
            'value' => (float) $fallback['value'],
            'severity' => (string) ($fallback['severity'] ?? 'warning'),
        ];
    }

    /**
     * Test whether a numeric `$observed` value trips a resolved threshold.
     * Returns true on trip; null thresholds always return false.
     *
     * @param  array{comparator: string, value: float, severity: string}|null  $threshold
     */
    public function trips(?array $threshold, float $observed): bool
    {
        if ($threshold === null) {
            return false;
        }

        return match ($threshold['comparator']) {
            'gt' => $observed > $threshold['value'],
            'gte' => $observed >= $threshold['value'],
            'lt' => $observed < $threshold['value'],
            'lte' => $observed <= $threshold['value'],
            default => false,
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, WebserverHealthThreshold>  $rows
     */
    private function pickEngineSpecificFirst($rows, string $engine): ?WebserverHealthThreshold
    {
        $exact = $rows->firstWhere('engine', $engine);
        if ($exact !== null) {
            return $exact;
        }

        return $rows->firstWhere('engine', null);
    }

    /**
     * @return array{comparator: string, value: float, severity: string}
     */
    private function toArray(WebserverHealthThreshold $row): array
    {
        return [
            'comparator' => (string) $row->comparator,
            'value' => (float) $row->value,
            'severity' => (string) $row->severity,
        ];
    }
}
