<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Sites\Contracts\SiteWebserverProvisioner;
use Illuminate\Support\Str;

class SiteCaddyProvisioner extends AbstractSiteWebserverProvisioner implements SiteWebserverProvisioner
{
    public function __construct(
        protected CaddySiteConfigBuilder $builder,
        ?SitePlaceholderPageBuilder $placeholderPageBuilder = null,
    ) {
        parent::__construct($placeholderPageBuilder);
    }

    public function webserver(): string
    {
        return 'caddy';
    }

    public function provision(Site $site): string
    {
        $server = $this->ensureServerReady($site);

        $config = $this->builder->build($site);
        $configFile = rtrim(config('sites.caddy_sites_enabled'), '/').'/'.$this->configBasename($site).'.caddy';
        $importLine = 'import /etc/caddy/sites-enabled/*.caddy';

        $ssh = $this->systemSsh($site);
        $this->installPlaceholderPage($site, $ssh);
        $this->writeSystemFile($ssh, $configFile, $config);
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_CADDY_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'mkdir -p /etc/caddy/sites-enabled /var/log/caddy && touch /etc/caddy/Caddyfile && (grep -Fqx %1$s /etc/caddy/Caddyfile || printf "\n%%s\n" %2$s >> /etc/caddy/Caddyfile) && caddy validate --config /etc/caddy/Caddyfile && (systemctl reload caddy 2>/dev/null || service caddy reload 2>/dev/null || systemctl restart caddy)',
                    escapeshellarg($importLine),
                    escapeshellarg($importLine)
                )
            )
        ), 120);

        $caddyOk = (bool) preg_match('/DPLY_CADDY_EXIT:0\s*$/', $out);
        if (! $caddyOk) {
            throw new \RuntimeException('Caddy validate or reload failed. Output: '.Str::limit($out, 2000));
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['caddy_last_output'] = $out;

        $site->update([
            'meta' => $meta,
        ]);

        return $out;
    }

    public function remove(Site $site): string
    {
        $server = $this->ensureServerReady($site);

        $configFile = rtrim(config('sites.caddy_sites_enabled'), '/').'/'.$this->configBasename($site).'.caddy';

        $ssh = $this->systemSsh($site);
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_CADDY_REMOVE_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'rm -f %s && caddy validate --config /etc/caddy/Caddyfile && (systemctl reload caddy 2>/dev/null || service caddy reload 2>/dev/null || systemctl restart caddy)',
                    escapeshellarg($configFile)
                )
            )
        ), 120);

        if (! preg_match('/DPLY_CADDY_REMOVE_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Caddy config cleanup failed. Output: '.Str::limit($out, 2000));
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['caddy_cleanup_output'] = $out;

        $site->update(['meta' => $meta]);

        return $out;
    }
}
