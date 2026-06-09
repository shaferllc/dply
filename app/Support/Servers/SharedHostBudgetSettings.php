<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\Site;

/**
 * Per-site soft budget settings stored on server meta.
 */
final class SharedHostBudgetSettings
{
    /**
     * @return array{
     *     alerts_enabled: bool,
     *     defaults: array{cpu_share_pct: float, mem_share_pct: float},
     *     sites: array<string, array{cpu_share_pct: ?float, mem_share_pct: ?float}>,
     *     site_rows: list<array{slug: string, name: string, cpu_share_pct: float, mem_share_pct: float}>,
     * }
     */
    public function forServer(Server $server): array
    {
        $server->loadMissing('sites');
        $stored = $this->read($server);
        $defaults = $this->defaults();

        $siteRows = $server->sites
            ->sortBy('name')
            ->values()
            ->map(function (Site $site) use ($stored, $defaults): array {
                $slug = (string) $site->slug;
                $override = is_array($stored['sites'][$slug] ?? null) ? $stored['sites'][$slug] : [];

                return [
                    'slug' => $slug,
                    'name' => (string) $site->name,
                    'cpu_share_pct' => (float) ($override['cpu_share_pct'] ?? $defaults['cpu_share_pct']),
                    'mem_share_pct' => (float) ($override['mem_share_pct'] ?? $defaults['mem_share_pct']),
                ];
            })
            ->all();

        return [
            'alerts_enabled' => (bool) ($stored['alerts_enabled'] ?? true),
            'defaults' => $defaults,
            'sites' => is_array($stored['sites'] ?? null) ? $stored['sites'] : [],
            'site_rows' => $siteRows,
        ];
    }

    /**
     * @param  array{alerts_enabled?: bool, site_rows?: list<array{slug?: string, cpu_share_pct?: float|int|string, mem_share_pct?: float|int|string}>}  $input
     */
    public function update(Server $server, array $input): void
    {
        $stored = $this->read($server);
        $stored['alerts_enabled'] = (bool) ($input['alerts_enabled'] ?? $stored['alerts_enabled'] ?? true);
        $stored['defaults'] = $this->defaults();

        $sites = [];
        foreach ($input['site_rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $sites[$slug] = [
                'cpu_share_pct' => $this->clampPct($row['cpu_share_pct'] ?? $stored['defaults']['cpu_share_pct']),
                'mem_share_pct' => $this->clampPct($row['mem_share_pct'] ?? $stored['defaults']['mem_share_pct']),
            ];
        }

        $stored['sites'] = $sites;

        $meta = $server->meta ?? [];
        $meta[(string) config('server_shared_host.budgets.meta_key', 'shared_host_budgets')] = $stored;
        $server->update(['meta' => $meta]);
    }

    /**
     * @return array{cpu_share_pct: float, mem_share_pct: float}
     */
    public function budgetForSite(Server $server, string $slug): array
    {
        $settings = $this->forServer($server);
        $override = is_array($settings['sites'][$slug] ?? null) ? $settings['sites'][$slug] : [];

        return [
            'cpu_share_pct' => (float) ($override['cpu_share_pct'] ?? $settings['defaults']['cpu_share_pct']),
            'mem_share_pct' => (float) ($override['mem_share_pct'] ?? $settings['defaults']['mem_share_pct']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(Server $server): array
    {
        $key = (string) config('server_shared_host.budgets.meta_key', 'shared_host_budgets');
        $stored = $server->meta[$key] ?? [];

        return is_array($stored) ? $stored : [];
    }

    /**
     * @return array{cpu_share_pct: float, mem_share_pct: float}
     */
    private function defaults(): array
    {
        return [
            'cpu_share_pct' => (float) config('server_shared_host.budgets.default_cpu_share_pct', 50),
            'mem_share_pct' => (float) config('server_shared_host.budgets.default_mem_share_pct', 50),
        ];
    }

    private function clampPct(mixed $value): float
    {
        return max(1.0, min(100.0, (float) $value));
    }
}
