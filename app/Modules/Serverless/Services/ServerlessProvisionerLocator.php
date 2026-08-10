<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Deploy\Services\ServerlessProviders\DigitalOcean\DigitalOceanOpenWhiskActionProvisioner;

/**
 * Builds the backend object for a function's host, from the credentials the
 * host was provisioned with.
 *
 * The deploy path constructs its provisioner from container bindings and
 * environment config; this is the *runtime* path — configuring, invoking, and
 * polling a function that already exists on a specific host. Those callers
 * need the backend addressed at the site's own namespace, which only the
 * Server row knows.
 *
 * Returns null when the host has no backend implementation or has not
 * finished provisioning; every caller treats that as "this host can't do
 * that", never as an error.
 */
class ServerlessProvisionerLocator
{
    public function forSite(Site $site): ?object
    {
        $site->loadMissing('server');
        $server = $site->server;

        if (! $server instanceof Server) {
            return null;
        }

        return $this->forServer($server, trim((string) ($site->serverlessConfig()['package'] ?? '')));
    }

    public function forServer(Server $server, string $package = ''): ?object
    {
        if (! $server->isDigitalOceanFunctionsHost()) {
            return null;
        }

        $credentials = $this->credentials($server);
        if ($credentials === []) {
            return null;
        }

        // Only the addressing arguments matter for runtime calls — the zip
        // path/size guards and runtime defaults are deploy-time concerns, so
        // they take the configured defaults here.
        return new DigitalOceanOpenWhiskActionProvisioner(
            apiHost: $credentials['api_host'],
            namespace: $credentials['namespace'],
            accessKey: $credentials['access_key'],
            zipPathPrefix: (string) (config('serverless.digitalocean.zip_path_prefix') ?? ''),
            zipMaxBytes: (int) config('serverless.digitalocean.zip_max_bytes', 45 * 1024 * 1024),
            defaultActionKind: (string) config('serverless.digitalocean.default_action_kind', 'nodejs:18'),
            defaultActionMain: (string) config('serverless.digitalocean.default_action_main', 'main'),
            // The implicit default package takes no path segment.
            defaultPackage: $package === 'default' ? '' : $package,
        );
    }

    /**
     * The addressing context a backend call needs, in the shape the
     * provisioners read.
     *
     * @return array<string, mixed>
     */
    public function contextForSite(Site $site): array
    {
        $site->loadMissing('server');
        $server = $site->server;
        $package = trim((string) ($site->serverlessConfig()['package'] ?? ''));

        return [
            'credentials' => $server instanceof Server ? $this->credentials($server) : [],
            'project' => ['settings' => [
                'digitalocean_functions_package' => $package === 'default' ? '' : $package,
            ]],
        ];
    }

    /**
     * @return array{api_host: string, namespace: string, access_key: string}|array{}
     */
    private function credentials(Server $server): array
    {
        $cfg = $server->meta['digitalocean_functions'] ?? null;
        $cfg = is_array($cfg) ? $cfg : [];

        $apiHost = rtrim((string) ($cfg['api_host'] ?? ''), '/');
        $namespace = trim((string) ($cfg['namespace'] ?? ''));
        $accessKey = trim((string) ($cfg['access_key'] ?? ''));

        if ($apiHost === '' || $namespace === '' || ! str_contains($accessKey, ':')) {
            return [];
        }

        return ['api_host' => $apiHost, 'namespace' => $namespace, 'access_key' => $accessKey];
    }
}
