<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Console\Command;

/**
 * Print an inventory of every domain across the fleet.
 *
 *   dply:fleet:domain-list                        # all domains
 *   dply:fleet:domain-list --primary-only         # primaries only
 *   dply:fleet:domain-list --runtime=node
 *   dply:fleet:domain-list --json
 *
 * Useful for DNS audits ("what URLs do we own"), bulk certificate
 * sanity checks, and inventory exports. Output sorted by hostname
 * for deterministic diffing across runs.
 *
 * Reports for each domain: hostname, primary flag, site name +
 * runtime, server name + IP. Filters narrow the result to a
 * specific runtime or to primary domains only.
 */
class ListFleetDomainsCommand extends Command
{
    protected $signature = 'dply:fleet:domain-list
        {--primary-only : Only include primary domains}
        {--runtime= : Filter to sites with this runtime}
        {--json : Output as JSON}';

    protected $description = 'Inventory every domain across the fleet.';

    public function handle(): int
    {
        $primaryOnly = (bool) $this->option('primary-only');
        $runtimeFilter = $this->option('runtime');

        $domainsQuery = SiteDomain::query()
            ->select(['id', 'site_id', 'hostname', 'is_primary']);
        if ($primaryOnly) {
            $domainsQuery->where('is_primary', true);
        }
        $domains = $domainsQuery->orderBy('hostname')->get();

        $sites = Site::query()
            ->whereIn('id', $domains->pluck('site_id')->unique())
            ->get(['id', 'name', 'slug', 'server_id', 'runtime'])
            ->keyBy('id');

        if ($runtimeFilter !== null) {
            $sites = $sites->filter(fn ($s) => $s->runtime === $runtimeFilter);
            $domains = $domains->filter(fn ($d) => $sites->has($d->site_id))->values();
        }

        $servers = Server::query()
            ->whereIn('id', $sites->pluck('server_id')->filter()->unique())
            ->get(['id', 'name', 'ip_address'])
            ->keyBy('id');

        $rows = [];
        foreach ($domains as $d) {
            $site = $sites->get($d->site_id);
            if ($site === null) {
                continue;
            }
            $server = $site->server_id ? $servers->get($site->server_id) : null;
            $rows[] = [
                'hostname' => $d->hostname,
                'is_primary' => (bool) $d->is_primary,
                'site_id' => $site->id,
                'site_name' => $site->name,
                'site_runtime' => $site->runtime,
                'server_id' => $server?->id,
                'server_name' => $server?->name,
                'server_ip' => $server?->ip_address,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'count' => count($rows),
                'primary_only' => $primaryOnly,
                'runtime_filter' => $runtimeFilter,
                'domains' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No domains match the filter.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d domain(s) in the fleet.', count($rows)));
        $this->newLine();
        $this->table(
            ['hostname', 'site', 'runtime', 'server', 'primary'],
            array_map(fn (array $r) => [
                $r['hostname'],
                $r['site_name'],
                $r['site_runtime'] ?? '—',
                $r['server_name'] ?? '—',
                $r['is_primary'] ? '★' : '',
            ], $rows),
        );

        return self::SUCCESS;
    }
}
