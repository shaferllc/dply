<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AttachCloudDomainJob;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Attach a custom hostname to an cloud container site.
 *
 *   dply:cloud:domain:attach <site> <hostname>
 *
 * Queues AttachCloudDomainJob, which talks to the backend's
 * attachDomain verb. App Runner returns DNS validation records
 * (CNAME) the operator must publish at their registrar; DO uses
 * an ALIAS / A record pointing at the default ingress and
 * validates live. Both flows record the attachment under
 * meta.container.domains.
 */
class CloudDomainAttachCommand extends Command
{
    protected $signature = 'dply:cloud:domain:attach
        {site : Site ID, slug, or name}
        {hostname : Fully-qualified hostname (e.g. api.example.com)}';

    protected $description = 'Attach a custom domain to an cloud container site.';

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

        $hostname = $this->normalizeHostname((string) $this->argument('hostname'));
        if ($hostname === null) {
            $this->error('Hostname does not look valid.');

            return self::FAILURE;
        }

        AttachCloudDomainJob::dispatch($site->id, $hostname);
        $this->info(sprintf('Domain attach queued for %s on %s.', $hostname, $site->name));

        return self::SUCCESS;
    }

    private function normalizeHostname(string $raw): ?string
    {
        $hostname = strtolower(trim($raw));
        $hostname = (string) preg_replace('#^https?://#', '', $hostname);
        $hostname = rtrim($hostname, '/');
        if ($hostname === '' || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i', $hostname)) {
            return null;
        }

        return $hostname;
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
