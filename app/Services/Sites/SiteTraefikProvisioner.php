<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Sites\Contracts\SiteWebserverProvisioner;
use Illuminate\Support\Str;

class SiteTraefikProvisioner extends AbstractSiteWebserverProvisioner implements SiteWebserverProvisioner
{
    public function __construct(
        protected TraefikSiteConfigBuilder $builder,
        protected CaddySiteConfigBuilder $caddyBuilder,
        ?SitePlaceholderPageBuilder $placeholderPageBuilder = null,
    ) {
        parent::__construct($placeholderPageBuilder);
    }

    public function webserver(): string
    {
        return 'traefik';
    }

    public function provision(Site $site): string
    {
        $server = $this->ensureServerReady($site);
        $backendPort = $this->backendPort($site);
        $basename = $this->configBasename($site);
        $dynamicConfig = rtrim(config('sites.traefik_dynamic_config_path'), '/').'/'.$basename.'.yml';
        $caddyConfig = rtrim(config('sites.caddy_sites_enabled'), '/').'/'.$basename.'-backend.caddy';
        $importLine = 'import /etc/caddy/sites-enabled/*.caddy';

        $ssh = $this->systemSsh($site);
        $this->installPlaceholderPage($site, $ssh);
        $this->ensureSuspendedPage($site, $ssh);
        $this->syncBasicAuthHtpasswdFiles($site, $ssh);
        $this->writeSystemFile($ssh, $caddyConfig, $this->caddyBuilder->build($site, $backendPort));
        $this->writeSystemFile($ssh, $dynamicConfig, $this->builder->build($site, $backendPort));

        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_TRAEFIK_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'mkdir -p /etc/caddy/sites-enabled /var/log/caddy %1$s && touch /etc/caddy/Caddyfile && (grep -Fqx %2$s /etc/caddy/Caddyfile || printf "\n%%s\n" %3$s >> /etc/caddy/Caddyfile) && caddy validate --config /etc/caddy/Caddyfile && (systemctl reload caddy 2>/dev/null || service caddy reload 2>/dev/null || systemctl restart caddy) && (systemctl reload traefik 2>/dev/null || systemctl restart traefik 2>/dev/null || true)',
                    escapeshellarg(rtrim(config('sites.traefik_dynamic_config_path'), '/')),
                    escapeshellarg($importLine),
                    escapeshellarg($importLine)
                )
            )
        ), 180);

        if (! preg_match('/DPLY_TRAEFIK_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Traefik provisioning failed. Output: '.Str::limit($out, 2000));
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['traefik_backend_port'] = $backendPort;
        $meta['traefik_last_output'] = $out;
        $site->update(['meta' => $meta]);

        return $out;
    }

    public function readCurrentDynamicConfig(Site $site): ?string
    {
        $server = $this->ensureServerReady($site);
        $ssh = $this->systemSsh($site);
        $basename = $this->configBasename($site);
        $dynamicConfig = rtrim(config('sites.traefik_dynamic_config_path'), '/').'/'.$basename.'.yml';

        return $this->readRemoteFile($server, $ssh, $dynamicConfig);
    }

    public function remove(Site $site): string
    {
        $server = $this->ensureServerReady($site);
        $basename = $this->configBasename($site);
        $dynamicConfig = rtrim(config('sites.traefik_dynamic_config_path'), '/').'/'.$basename.'.yml';
        $caddyConfig = rtrim(config('sites.caddy_sites_enabled'), '/').'/'.$basename.'-backend.caddy';

        $ssh = $this->systemSsh($site);
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_TRAEFIK_REMOVE_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'rm -f %1$s %2$s && caddy validate --config /etc/caddy/Caddyfile && (systemctl reload caddy 2>/dev/null || service caddy reload 2>/dev/null || systemctl restart caddy) && (systemctl reload traefik 2>/dev/null || systemctl restart traefik 2>/dev/null || true)',
                    escapeshellarg($dynamicConfig),
                    escapeshellarg($caddyConfig)
                )
            )
        ), 180);

        if (! preg_match('/DPLY_TRAEFIK_REMOVE_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Traefik cleanup failed. Output: '.Str::limit($out, 2000));
        }

        $this->updateSiteMeta($site, 'traefik_cleanup_output', $out);

        return $out;
    }

    private function backendPort(Site $site): int
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $existing = $meta['traefik_backend_port'] ?? null;
        if (is_numeric($existing) && (int) $existing >= 20000) {
            return (int) $existing;
        }

        return 20000 + (abs(crc32((string) $site->getKey())) % 20000);
    }
}
