<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Models\Site;
use App\Modules\Cloud\Backends\CloudRouter;
use Illuminate\Console\Command;

/**
 * List recent deployments for an cloud container site.
 *
 *   dply:cloud:deployments <site> [--limit=10] [--json]
 *
 * Backend behavior:
 *   - DigitalOcean App Platform: pulls live deployment list from
 *     /v2/apps/{id}/deployments (newest first).
 *   - AWS App Runner: returns a single synthetic latest entry
 *     derived from local meta — App Runner's full history is in
 *     the AWS console.
 *   - FakeCloudBackend: returns synthetic entries so dev installs
 *     see something useful.
 */
class CloudDeploymentsCommand extends Command
{
    protected $signature = 'dply:cloud:deployments
        {site : Site ID, slug, or name}
        {--limit=10 : How many deployments to fetch (1-100)}
        {--json : Output as JSON}';

    protected $description = 'List recent deployments for an cloud container site.';

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

        $limitRaw = $this->option('limit');
        $limit = is_string($limitRaw) && ctype_digit($limitRaw) ? (int) $limitRaw : 10;
        $limit = max(1, min(100, $limit));

        $backend = CloudRouter::backendFor($site);
        $credential = CloudRouter::credentialFor($site);
        if ($backend === null || $credential === null) {
            $this->error('No backend or credential resolvable for this site.');

            return self::FAILURE;
        }

        try {
            $deployments = $backend->recentDeployments($site, $credential, $limit);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch deployments: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'site' => $site->name,
                'total' => count($deployments),
                'deployments' => $deployments,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($deployments === []) {
            $this->line('<fg=gray>No deployments yet.</>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Recent deployments for</> '.$site->name);
        $this->newLine();
        $this->table(
            ['id', 'phase', 'started', 'finished', 'cause'],
            array_map(fn (array $d): array => [
                substr((string) $d['id'], 0, 12),
                (string) $d['phase'],
                substr((string) ($d['started_at'] ?? '—'), 0, 19),
                substr((string) ($d['finished_at'] ?? '—'), 0, 19),
                (string) ($d['cause'] ?? '—'),
            ], $deployments),
        );

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
