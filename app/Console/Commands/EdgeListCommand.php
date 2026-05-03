<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SiteType;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Prints all edge container sites in the fleet — the CLI mirror
 * of the /edge index page. Useful for ops scripts and "what is
 * deployed where" sanity checks from the terminal.
 *
 *   dply:edge:list [--json] [--backend=digitalocean_app_platform]
 *                  [--status=active|provisioning|failed]
 */
class EdgeListCommand extends Command
{
    protected $signature = 'dply:edge:list
        {--json : Output as JSON}
        {--backend= : Filter to a single backend slug}
        {--status= : Filter to a single status (active, provisioning, failed)}';

    protected $description = 'List edge container sites across the fleet.';

    public function handle(): int
    {
        $query = Site::query()
            ->where(function ($q): void {
                $q->where('type', SiteType::Container)
                    ->orWhereNotNull('container_backend');
            })
            ->with(['organization:id,name', 'server:id,name'])
            ->orderBy('organization_id')
            ->orderBy('name');

        if (is_string($this->option('backend')) && $this->option('backend') !== '') {
            $query->where('container_backend', $this->option('backend'));
        }

        if (is_string($this->option('status')) && $this->option('status') !== '') {
            $statusMap = [
                'active' => Site::STATUS_CONTAINER_ACTIVE,
                'provisioning' => Site::STATUS_CONTAINER_PROVISIONING,
                'failed' => Site::STATUS_CONTAINER_FAILED,
            ];
            $statusKey = strtolower((string) $this->option('status'));
            if (! isset($statusMap[$statusKey])) {
                $this->error('Unknown --status. Use one of: '.implode(', ', array_keys($statusMap)));

                return self::FAILURE;
            }
            $query->where('status', $statusMap[$statusKey]);
        }

        $sites = $query->get();

        $rows = $sites->map(function (Site $site): array {
            return [
                'site' => $site->name,
                'organization' => $site->organization?->name ?? '—',
                'backend' => $site->container_backend ?? '—',
                'region' => $site->container_region ?? '—',
                'image' => $site->container_image ?? '—',
                'status' => $site->status,
                'live_url' => $site->containerLiveUrl(),
            ];
        })->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'total' => count($rows),
                'sites' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('<fg=gray>No edge sites found.</>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Edge container sites</> ('.count($rows).')');
        $this->newLine();

        $this->table(
            ['site', 'organization', 'backend', 'region', 'image', 'status', 'live url'],
            array_map(fn (array $r): array => [
                $r['site'],
                $r['organization'],
                $r['backend'],
                $r['region'],
                $r['image'],
                $r['status'],
                $r['live_url'] ?? '—',
            ], $rows),
        );

        return self::SUCCESS;
    }
}
