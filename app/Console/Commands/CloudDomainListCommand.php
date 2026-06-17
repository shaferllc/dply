<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;

/**
 * List custom domains attached to an cloud site.
 *
 *   dply:cloud:domain:list <site> [--json]
 *
 * Reads meta.container.domains (populated by AttachCloudDomainJob
 * with hostname → { attached_at, status, validation }). Useful for
 * reconciling expected DNS state from a script.
 */
class CloudDomainListCommand extends Command
{
    protected $signature = 'dply:cloud:domain:list
        {site : Site ID, slug, or name}
        {--json : Output as JSON}';

    protected $description = 'List custom domains attached to an cloud container site.';

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

        $meta = is_array($site->meta) ? $site->meta : [];
        $domains = is_array($meta['container']['domains'] ?? null) ? $meta['container']['domains'] : [];

        $rows = [];
        foreach ($domains as $hostname => $info) {
            if (! is_array($info)) {
                continue;
            }
            $rows[] = [
                'hostname' => (string) $hostname,
                'status' => is_string($info['status'] ?? null) ? $info['status'] : null,
                'attached_at' => is_string($info['attached_at'] ?? null) ? $info['attached_at'] : null,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'site' => $site->name,
                'live_url' => $site->containerLiveUrl(),
                'total' => count($rows),
                'domains' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line(sprintf('<fg=gray>No custom domains attached to %s.</>', $site->name));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Domains for</> '.$site->name);
        $this->newLine();
        $this->table(
            ['hostname', 'status', 'attached'],
            array_map(fn ($r) => [$r['hostname'], $r['status'] ?? '—', $r['attached_at'] ?? '—'], $rows),
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
