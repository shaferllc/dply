<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\EnvoyEdgeConfigBuilder;
use App\Services\Servers\HAProxyEdgeConfigBuilder;
use App\Services\Servers\OpenRestyEdgeConfigBuilder;
use App\Services\Servers\TraefikStaticConfigOptions;
use App\Services\SshConnection;
use App\Support\Servers\CaddyRuntimeOwnership;
use App\Support\Servers\EnvoyAdminScript;
use App\Support\Sites\EdgeBackendPortResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * When an L7 edge proxy is active, every site is served via Caddy on a high
 * port (+ optional Caddy TLS on :443). The edge proxy owns :80 and routes
 * by Host header — {@see SiteCaddyProvisioner} must not rewrite :80 configs.
 */
class SiteEdgeBackendProvisioner extends AbstractSiteWebserverProvisioner
{
    public function __construct(
        protected CaddySiteConfigBuilder $caddyBuilder,
        protected TraefikSiteConfigBuilder $traefikBuilder,
        ?SitePlaceholderPageBuilder $placeholderPageBuilder = null,
    ) {
        parent::__construct($placeholderPageBuilder);
    }

    public function webserver(): string
    {
        return 'edge_backend';
    }

    public function provision(Site $site, ?ConsoleEmitter $emit = null): string
    {
        $site->loadMissing('server');
        $edgeProxy = $site->server?->edgeProxy();
        if (! is_string($edgeProxy) || ! in_array($edgeProxy, ['traefik', 'haproxy', 'envoy', 'openresty'], true)) {
            throw new \RuntimeException('Server has no active edge proxy for backend provisioning.');
        }

        return $this->provisionForEdge($site, $edgeProxy, $emit);
    }

    public function provisionForEdge(Site $site, string $edgeProxy, ?ConsoleEmitter $emit = null): string
    {
        $emit ??= new ConsoleEmitter;

        if (! in_array($edgeProxy, ['traefik', 'haproxy', 'envoy', 'openresty'], true)) {
            throw new \InvalidArgumentException('Unsupported edge proxy ['.$edgeProxy.'].');
        }

        $emit->step('edge', 'resolving server connection');
        $server = $this->ensureServerReady($site);
        $backendPort = EdgeBackendPortResolver::for($site);
        $basename = $this->configBasename($site);
        $sitesEnabled = rtrim((string) config('sites.caddy_sites_enabled'), '/');
        $backendConfig = $sitesEnabled.'/'.$basename.'-backend.caddy';
        $tlsConfig = $sitesEnabled.'/'.$basename.'-tls.caddy';
        $legacyConfig = $sitesEnabled.'/'.$basename.'.caddy';
        $importLine = 'import /etc/caddy/sites-enabled/*.caddy';

        $ssh = $this->systemSsh($site);

        if (! isset(($site->meta ?? [])['edge_backend_last_output'])) {
            $this->installPlaceholderPage($site, $ssh, $emit);
        }
        $this->ensureSuspendedPage($site, $ssh, $emit);
        $this->syncBasicAuthHtpasswdFiles($site, $ssh, $emit);
        $this->syncAccessGateFiles($site, $ssh, $emit);

        if ($this->writeSystemFileIfChanged($server, $ssh, $backendConfig, $this->caddyBuilder->build($site, $backendPort))) {
            $emit->step('edge', 'writing caddy backend config: '.$backendConfig);
        }

        $tlsContents = $this->caddyBuilder->buildEdgeTlsFront($site, $backendPort);
        if ($tlsContents === '') {
            $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($tlsConfig)), 15);
        } elseif ($this->writeSystemFileIfChanged($server, $ssh, $tlsConfig, $tlsContents)) {
            $emit->step('edge', 'writing caddy TLS front: '.$tlsConfig);
        }

        $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($legacyConfig)), 15);

        if ($edgeProxy === 'traefik') {
            $dynamicConfig = rtrim((string) config('sites.traefik_dynamic_config_path'), '/').'/'.$basename.'.yml';
            if ($this->writeSystemFileIfChanged($server, $ssh, $dynamicConfig, $this->traefikBuilder->build($site, $backendPort))) {
                $emit->step('edge', 'writing traefik dynamic config: '.$dynamicConfig);
            }
        }

        $allSites = $this->sitesForEdgeConfig($server);
        $this->regenerateMonolithicEdgeConfig($server, $ssh, $edgeProxy, $allSites, listenPort: 80);

        $emit->step('edge', 'validating caddy + reloading edge proxy');
        $reloadCmd = $this->reloadCommandForEdge($edgeProxy);

        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_EDGE_BACKEND_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'mkdir -p /etc/caddy/sites-enabled /var/log/caddy %1$s && touch /etc/caddy/Caddyfile && (grep -Fqx %2$s /etc/caddy/Caddyfile || printf "\n%%s\n" %3$s >> /etc/caddy/Caddyfile) && %4$s && %5$s',
                    $edgeProxy === 'traefik'
                        ? escapeshellarg(rtrim((string) config('sites.traefik_dynamic_config_path'), '/'))
                        : ($edgeProxy === 'envoy'
                            ? '/etc/envoy'
                            : ($edgeProxy === 'openresty' ? '/etc/openresty' : '/etc/haproxy')),
                    escapeshellarg($importLine),
                    escapeshellarg($importLine),
                    CaddyRuntimeOwnership::shell(),
                    $reloadCmd,
                ),
            )
        ), 180);

        foreach (preg_split('/\r\n|\r|\n/', trim($out)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $emit($line, ConsoleAction::LEVEL_INFO, 'edge');
        }

        if (! preg_match('/DPLY_EDGE_BACKEND_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Edge backend provisioning failed. Output: '.Str::limit($out, 2000));
        }

        $emit->success('reload OK', 'edge');

        $site->update([
            'meta' => array_merge(
                EdgeBackendPortResolver::metaWithPinnedPort($site, $backendPort),
                ['edge_backend_last_output' => $out],
            ),
        ]);

        return $out;
    }

    /**
     * Rebuild every site's edge-backend configs and the monolithic edge routing
     * file in one pass — the server-wide "Apply webserver config" repair action.
     */
    public function syncAllForServer(Server $server, ?ConsoleEmitter $emit = null): string
    {
        $emit ??= new ConsoleEmitter;

        $edgeProxy = $server->edgeProxy();
        if (! is_string($edgeProxy) || ! in_array($edgeProxy, ['traefik', 'haproxy', 'envoy', 'openresty'], true)) {
            throw new \RuntimeException('Server has no active edge proxy for backend sync.');
        }

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $sites = $this->sitesForEdgeConfig($server);
        if ($sites->isEmpty()) {
            throw new \RuntimeException('No sites on this server to sync.');
        }

        $emit->step('edge', sprintf('syncing %d site backend(s) for %s edge', $sites->count(), $edgeProxy));

        $anchor = $sites->first();
        if ($anchor === null) {
            throw new \RuntimeException('No sites on this server to sync.');
        }

        $ssh = $this->systemSsh($anchor);
        $sitesEnabled = rtrim((string) config('sites.caddy_sites_enabled'), '/');
        $importLine = 'import /etc/caddy/sites-enabled/*.caddy';
        $syncedSiteIds = [];

        foreach ($sites as $site) {
            if ($site->usesFunctionsRuntime() || $site->usesKubernetesRuntime()) {
                continue;
            }
            if ($site->usesDockerRuntime() && ! $site->usesVmDockerRuntime()) {
                continue;
            }
            if ($site->webserverHostnames() === []) {
                continue;
            }

            $backendPort = EdgeBackendPortResolver::for($site);
            $basename = $this->configBasename($site);
            $backendConfig = $sitesEnabled.'/'.$basename.'-backend.caddy';
            $tlsConfig = $sitesEnabled.'/'.$basename.'-tls.caddy';
            $legacyConfig = $sitesEnabled.'/'.$basename.'.caddy';

            $emit->step('edge', 'site '.$site->name.': writing backend + TLS configs');

            if (! isset(($site->meta ?? [])['edge_backend_last_output'])) {
                $this->installPlaceholderPage($site, $ssh, $emit);
            }
            $this->ensureSuspendedPage($site, $ssh, $emit);
            $this->syncBasicAuthHtpasswdFiles($site, $ssh, $emit);
            $this->syncAccessGateFiles($site, $ssh, $emit);

            $this->writeSystemFileIfChanged($server, $ssh, $backendConfig, $this->caddyBuilder->build($site, $backendPort));

            $tlsContents = $this->caddyBuilder->buildEdgeTlsFront($site, $backendPort);
            if ($tlsContents === '') {
                $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($tlsConfig)), 15);
            } else {
                $this->writeSystemFileIfChanged($server, $ssh, $tlsConfig, $tlsContents);
            }

            $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($legacyConfig)), 15);

            if ($edgeProxy === 'traefik') {
                $dynamicConfig = rtrim((string) config('sites.traefik_dynamic_config_path'), '/').'/'.$basename.'.yml';
                $this->writeSystemFileIfChanged($server, $ssh, $dynamicConfig, $this->traefikBuilder->build($site, $backendPort));
            }

            $site->update([
                'meta' => array_merge(
                    EdgeBackendPortResolver::metaWithPinnedPort($site, $backendPort),
                    ['edge_backend_last_output' => 'synced at '.now()->toIso8601String()],
                ),
            ]);
            $syncedSiteIds[] = (string) $site->getKey();
        }

        if ($syncedSiteIds === []) {
            throw new \RuntimeException('No eligible sites with hostnames were found to sync.');
        }

        $emit->step('edge', 'regenerating edge routing config');
        $this->regenerateMonolithicEdgeConfig($server, $ssh, $edgeProxy, $sites, listenPort: 80);

        $emit->step('edge', 'validating caddy + reloading edge proxy');
        $reloadCmd = $this->reloadCommandForEdge($edgeProxy);

        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_EDGE_BACKEND_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'mkdir -p /etc/caddy/sites-enabled /var/log/caddy %1$s && touch /etc/caddy/Caddyfile && (grep -Fqx %2$s /etc/caddy/Caddyfile || printf "\n%%s\n" %3$s >> /etc/caddy/Caddyfile) && %4$s && %5$s',
                    $edgeProxy === 'traefik'
                        ? escapeshellarg(rtrim((string) config('sites.traefik_dynamic_config_path'), '/'))
                        : ($edgeProxy === 'envoy'
                            ? '/etc/envoy'
                            : ($edgeProxy === 'openresty' ? '/etc/openresty' : '/etc/haproxy')),
                    escapeshellarg($importLine),
                    escapeshellarg($importLine),
                    CaddyRuntimeOwnership::shell(),
                    $reloadCmd,
                ),
            )
        ), 300);

        foreach (preg_split('/\r\n|\r|\n/', trim($out)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $emit($line, ConsoleAction::LEVEL_INFO, 'edge');
        }

        if (! preg_match('/DPLY_EDGE_BACKEND_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Edge backend sync failed. Output: '.Str::limit($out, 2000));
        }

        $emit->success(sprintf('Synced %d site(s)', count($syncedSiteIds)), 'edge');

        return $out;
    }

    private function reloadCommandForEdge(string $edgeProxy): string
    {
        return match ($edgeProxy) {
            'traefik' => 'caddy validate --config /etc/caddy/Caddyfile && '
                .'(systemctl reload caddy 2>/dev/null || systemctl restart caddy) && '
                .'(systemctl reload traefik 2>/dev/null || systemctl restart traefik 2>/dev/null || true)',
            'haproxy' => 'caddy validate --config /etc/caddy/Caddyfile && '
                .'(systemctl reload caddy 2>/dev/null || systemctl restart caddy) && '
                .'haproxy -c -f /etc/haproxy/haproxy.cfg && '
                .'(systemctl reload haproxy 2>/dev/null || systemctl restart haproxy 2>/dev/null || true)',
            'envoy' => EnvoyAdminScript::preparePort80Script()
                ."set -e\n"
                .'caddy validate --config /etc/caddy/Caddyfile && '
                .'(systemctl reload caddy 2>/dev/null || systemctl restart caddy) && '
                .'envoy --mode validate -c /etc/envoy/envoy.yaml && '
                .'(systemctl restart envoy 2>/dev/null || true) && '
                .EnvoyAdminScript::waitUntilReady(attempts: 25, sleepSeconds: 1),
            'openresty' => 'caddy validate --config /etc/caddy/Caddyfile && '
                .'(systemctl reload caddy 2>/dev/null || systemctl restart caddy) && '
                .'openresty -t && '
                .'(systemctl reload openresty 2>/dev/null || systemctl restart openresty 2>/dev/null || true)',
            default => 'true',
        };
    }

    public function remove(Site $site): string
    {
        $site->loadMissing('server');
        $edgeProxy = $site->server?->edgeProxy();
        if (! is_string($edgeProxy) || ! in_array($edgeProxy, ['traefik', 'haproxy', 'envoy', 'openresty'], true)) {
            throw new \RuntimeException('Server has no active edge proxy for backend cleanup.');
        }

        $server = $this->ensureServerReady($site);
        $basename = $this->configBasename($site);
        $sitesEnabled = rtrim((string) config('sites.caddy_sites_enabled'), '/');
        $paths = [
            $sitesEnabled.'/'.$basename.'-backend.caddy',
            $sitesEnabled.'/'.$basename.'-tls.caddy',
            $sitesEnabled.'/'.$basename.'.caddy',
        ];
        if ($edgeProxy === 'traefik') {
            $paths[] = rtrim((string) config('sites.traefik_dynamic_config_path'), '/').'/'.$basename.'.yml';
        }

        $ssh = $this->systemSsh($site);
        $rm = implode(' ', array_map(fn (string $path): string => escapeshellarg($path), $paths));
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_EDGE_BACKEND_REMOVE_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                'rm -f '.$rm.' && caddy validate --config /etc/caddy/Caddyfile && (systemctl reload caddy 2>/dev/null || systemctl restart caddy)',
            ),
        ), 120);

        if (! preg_match('/DPLY_EDGE_BACKEND_REMOVE_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Edge backend cleanup failed. Output: '.Str::limit($out, 2000));
        }

        $remainingSites = $this->sitesForEdgeConfig($server)
            ->reject(fn (Site $candidate): bool => (string) $candidate->getKey() === (string) $site->getKey())
            ->values();
        $this->regenerateMonolithicEdgeConfig($server, $ssh, $edgeProxy, $remainingSites, listenPort: 80);

        return $out;
    }

    /**
     * @return Collection<int, Site>
     */
    private function sitesForEdgeConfig(Server $server): Collection
    {
        return Site::query()
            ->where('server_id', $server->id)
            ->with(['domains', 'domainAliases', 'tenantDomains', 'previewDomains', 'redirects', 'basicAuthUsers', 'server'])
            ->get();
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function regenerateMonolithicEdgeConfig(
        Server $server,
        SshConnection $ssh,
        string $edgeProxy,
        Collection $sites,
        int $listenPort,
    ): void {
        match ($edgeProxy) {
            'traefik' => $this->writeTraefikStatic($server, $ssh, $listenPort),
            'haproxy' => $this->writeHaproxyConfig($server, $ssh, $sites, $listenPort),
            'envoy' => $this->writeEnvoyConfig($server, $ssh, $sites, $listenPort),
            'openresty' => $this->writeOpenRestyConfig($server, $ssh, $sites, $listenPort),
        };
    }

    private function writeTraefikStatic(Server $server, SshConnection $ssh, int $listenPort): void
    {
        $path = '/etc/traefik/traefik.yml';
        $contents = app(TraefikStaticConfigOptions::class)->renderCanonicalStaticYaml($listenPort);
        $this->writeSystemFile($ssh, $path, $contents);
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function writeHaproxyConfig(Server $server, SshConnection $ssh, Collection $sites, int $listenPort): void
    {
        $path = '/etc/haproxy/haproxy.cfg';
        $contents = app(HAProxyEdgeConfigBuilder::class)->build(
            $sites,
            $listenPort,
            fn (Site $s): int => EdgeBackendPortResolver::for($s),
        );
        $this->writeSystemFile($ssh, $path, $contents);
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function writeEnvoyConfig(Server $server, SshConnection $ssh, Collection $sites, int $listenPort): void
    {
        $path = '/etc/envoy/envoy.yaml';
        $contents = app(EnvoyEdgeConfigBuilder::class)->buildForServer(
            $server,
            $sites,
            $listenPort,
            fn (Site $s): int => EdgeBackendPortResolver::for($s),
        );
        $this->writeSystemFile($ssh, $path, $contents);
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function writeOpenRestyConfig(Server $server, SshConnection $ssh, Collection $sites, int $listenPort): void
    {
        $path = '/etc/openresty/nginx.conf';
        $contents = app(OpenRestyEdgeConfigBuilder::class)->buildForServer(
            $server,
            $sites,
            $listenPort,
            fn (Site $s): int => EdgeBackendPortResolver::for($s),
        );
        $this->writeSystemFile($ssh, $path, $contents);
    }
}
