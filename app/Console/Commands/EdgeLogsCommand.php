<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Edge\EdgeRouter;
use Illuminate\Console\Command;

/**
 * Fetch the latest deployment log link / content for an edge site.
 *
 *   dply:edge:logs <site>
 *
 * Backend behavior varies:
 *   - DigitalOcean App Platform returns a presigned URL the
 *     operator can curl / open in a browser.
 *   - AWS App Runner streams logs to CloudWatch — we surface the
 *     LogGroup name so the operator can open the AWS console.
 *   - FakeEdgeBackend returns synthetic inline content for tests
 *     and dev installs.
 */
class EdgeLogsCommand extends Command
{
    protected $signature = 'dply:edge:logs
        {site : Site ID, slug, or name}';

    protected $description = 'Fetch the latest deployment logs for an edge site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if (! is_string($site->container_backend) || $site->container_backend === '') {
            $this->error("Site {$site->name} is not an edge container site.");

            return self::FAILURE;
        }

        $backend = EdgeRouter::backendFor($site);
        $credential = EdgeRouter::credentialFor($site);
        if ($backend === null || $credential === null) {
            $this->error('No backend or credential resolvable for this site.');

            return self::FAILURE;
        }

        try {
            $logs = $backend->latestDeploymentLogs($site, $credential);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch logs: '.$e->getMessage());

            return self::FAILURE;
        }

        if (is_string($logs['content'] ?? null) && $logs['content'] !== '') {
            $this->line($logs['content']);

            return self::SUCCESS;
        }

        if (is_string($logs['url'] ?? null) && $logs['url'] !== '') {
            $this->line('Latest deployment logs available at:');
            $this->line($logs['url']);

            return self::SUCCESS;
        }

        if (is_string($logs['message'] ?? null) && $logs['message'] !== '') {
            $this->line($logs['message']);

            return self::SUCCESS;
        }

        $this->line('<fg=gray>No logs returned by backend.</>');

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
