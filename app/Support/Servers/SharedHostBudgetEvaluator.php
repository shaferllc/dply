<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;

/**
 * Detects per-site soft budget breaches from attribution rows.
 */
final class SharedHostBudgetEvaluator
{
    public function __construct(
        private SharedHostBudgetSettings $budgets,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{
     *     id: string,
     *     slug: string,
     *     name: string,
     *     metric: string,
     *     observed_pct: float,
     *     budget_pct: float,
     *     severity: string,
     *     title: string,
     *     message: string,
     * }>
     */
    public function breaches(Server $server, array $rows, bool $usePeakShares = false): array
    {
        $settings = $this->budgets->forServer($server);
        if (! ($settings['alerts_enabled'])) {
            return [];
        }

        $server->loadMissing('sites');
        $sitesBySlug = $server->sites->keyBy(static fn ($site) => (string) $site->slug);
        $breaches = [];

        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $budget = $this->budgets->budgetForSite($server, $slug);
            $site = $sitesBySlug->get($slug);
            $name = (string) ($row['name'] ?? ($site->name ?? $slug));

            $cpuShare = $usePeakShares
                ? ($row['peak_cpu_share_pct'] ?? $row['cpu_share_pct'] ?? null)
                : ($row['cpu_share_pct'] ?? $row['peak_cpu_share_pct'] ?? null);
            $memShare = $usePeakShares
                ? ($row['peak_mem_share_pct'] ?? $row['mem_share_pct'] ?? null)
                : ($row['mem_share_pct'] ?? $row['peak_mem_share_pct'] ?? null);

            if ($cpuShare !== null && (float) $cpuShare > (float) $budget['cpu_share_pct']) {
                $breaches[] = $this->breachRow(
                    slug: $slug,
                    name: $name,
                    metric: 'cpu',
                    observed: (float) $cpuShare,
                    budget: (float) $budget['cpu_share_pct'],
                );
            }

            if ($memShare !== null && (float) $memShare > (float) $budget['mem_share_pct']) {
                $breaches[] = $this->breachRow(
                    slug: $slug,
                    name: $name,
                    metric: 'mem',
                    observed: (float) $memShare,
                    budget: (float) $budget['mem_share_pct'],
                );
            }
        }

        return $breaches;
    }

    /**
     * @return array<string, mixed>
     */
    private function breachRow(string $slug, string $name, string $metric, float $observed, float $budget): array
    {
        $metricLabel = $metric === 'cpu' ? __('CPU share') : __('Memory share');

        return [
            'id' => 'budget-'.$slug.'-'.$metric,
            'slug' => $slug,
            'name' => $name,
            'metric' => $metric,
            'observed_pct' => $observed,
            'budget_pct' => $budget,
            'severity' => $observed >= ($budget + 20) ? 'critical' : 'warning',
            'title' => __('Soft budget exceeded'),
            'message' => __(':site exceeded its :metric budget (:observed% > :budget%).', [
                'site' => $name,
                'metric' => $metricLabel,
                'observed' => number_format($observed, 0),
                'budget' => number_format($budget, 0),
            ]),
        ];
    }
}
