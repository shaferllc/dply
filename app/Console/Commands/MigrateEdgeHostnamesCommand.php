<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Services\Edge\EdgeHostMapPublisher;
use App\Support\Edge\EdgeTestingDomains;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Move legacy Edge delivery hostnames from dply.host to on-dply.site.
 */
class MigrateEdgeHostnamesCommand extends Command
{
    protected $signature = 'dply:edge:migrate-hostnames
                            {--dry-run : Show planned changes without writing}
                            {--site= : Migrate a single site id}';

    protected $description = 'Migrate legacy Edge sites from dply.host to on-dply.site hostnames.';

    public function handle(EdgeHostMapPublisher $hostMapPublisher): int
    {
        $targetApex = EdgeTestingDomains::defaultApex();
        $dryRun = (bool) $this->option('dry-run');
        $siteId = $this->option('site');

        $query = Site::query()
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->where('edge_backend', 'dply_edge');

        if (is_string($siteId) && $siteId !== '') {
            $query->where('id', $siteId);
        }

        $sites = $query->get()->filter(function (Site $site): bool {
            $host = strtolower($site->edgeHostname());

            return str_ends_with($host, '.dply.host') && ! str_contains($host, '.on-dply.');
        });

        if ($sites->isEmpty()) {
            $this->info('No legacy dply.host Edge hostnames found.');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $oldHost = strtolower($site->edgeHostname());
            $prefix = (string) Str::beforeLast($oldHost, '.dply.host');
            $newHost = strtolower($prefix.'.'.$targetApex);

            $this->line("Site {$site->id} ({$site->name}): {$oldHost} → {$newHost}");

            if ($dryRun) {
                continue;
            }

            $meta = $site->edgeMeta();
            $routing = is_array($meta['routing'] ?? null) ? $meta['routing'] : [];
            $routing['hostname'] = $newHost;
            unset($routing['testing_dns']);
            $meta['routing'] = $routing;
            $meta['live_url'] = 'https://'.$newHost;

            $site->update([
                'meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta]),
            ]);

            $activeId = $meta['active_deployment_id'] ?? null;
            if (is_string($activeId) && $activeId !== '') {
                $deployment = EdgeDeployment::query()->find($activeId);
                if ($deployment instanceof EdgeDeployment) {
                    try {
                        $hostMapPublisher->unpublishHostname($site->fresh(), $oldHost);
                        $hostMapPublisher->publishHostname($site->fresh(), $deployment, $newHost);
                    } catch (\Throwable $e) {
                        $this->warn("  KV republish failed: {$e->getMessage()}");
                    }
                }
            }
        }

        $this->info($dryRun ? 'Dry run complete.' : 'Hostname migration complete. Redeploy affected sites if delivery looks stale.');

        return self::SUCCESS;
    }
}
