<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DetachCloudDomainJob;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Detach a previously-attached custom hostname from an cloud site.
 *
 *   dply:cloud:domain:detach <site> <hostname>
 *
 * Queues DetachCloudDomainJob — idempotent on the backend so
 * removing a hostname that was already detached out-of-band
 * doesn't fail.
 */
class CloudDomainDetachCommand extends Command
{
    protected $signature = 'dply:cloud:domain:detach
        {site : Site ID, slug, or name}
        {hostname : The hostname to remove}';

    protected $description = 'Detach a custom domain from an cloud container site.';

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

        $hostname = strtolower(trim((string) $this->argument('hostname')));
        if ($hostname === '') {
            $this->error('Hostname is required.');

            return self::FAILURE;
        }

        DetachCloudDomainJob::dispatch($site->id, $hostname);
        $this->info(sprintf('Domain detach queued for %s on %s.', $hostname, $site->name));

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
