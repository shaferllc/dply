<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Sites\Contracts\SiteWebserverProvisioner;
use Illuminate\Support\Str;

class SiteNginxProvisioner extends AbstractSiteWebserverProvisioner implements SiteWebserverProvisioner
{
    public function __construct(
        protected NginxSiteConfigBuilder $builder,
        ?SitePlaceholderPageBuilder $placeholderPageBuilder = null,
    ) {
        parent::__construct($placeholderPageBuilder);
    }

    public function webserver(): string
    {
        return 'nginx';
    }

    public function provision(Site $site): string
    {
        $server = $this->ensureServerReady($site);

        $config = $this->builder->build($site);
        $available = rtrim(config('sites.nginx_sites_available'), '/');
        $enabled = rtrim(config('sites.nginx_sites_enabled'), '/');
        $confFile = $available.'/'.$this->configBasename($site).'.conf';
        $linkFile = $enabled.'/'.$this->configBasename($site).'.conf';

        $ssh = $this->systemSsh($site);
        $this->installPlaceholderPage($site, $ssh);
        $this->ensureSuspendedPage($site, $ssh);
        $this->writeSystemFile($ssh, $confFile, $config);
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_NGINX_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'ln -sf %1$s %2$s && nginx -t && (systemctl reload nginx 2>/dev/null || service nginx reload 2>/dev/null || nginx -s reload)',
                    escapeshellarg($confFile),
                    escapeshellarg($linkFile)
                )
            ),
        ), 120);

        $nginxOk = (bool) preg_match('/DPLY_NGINX_EXIT:0\s*$/', $out);
        if (! $nginxOk) {
            throw new \RuntimeException('Nginx test or reload failed. Output: '.Str::limit($out, 2000));
        }

        $site->update([
            'nginx_installed_at' => now(),
            'meta' => array_merge($site->meta ?? [], ['nginx_last_output' => $out]),
        ]);

        return $out;
    }

    public function remove(Site $site): string
    {
        $server = $this->ensureServerReady($site);

        $available = rtrim(config('sites.nginx_sites_available'), '/');
        $enabled = rtrim(config('sites.nginx_sites_enabled'), '/');
        $confFile = $available.'/'.$this->configBasename($site).'.conf';
        $linkFile = $enabled.'/'.$this->configBasename($site).'.conf';

        $ssh = $this->systemSsh($site);
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_NGINX_REMOVE_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'rm -f %1$s %2$s && nginx -t && (systemctl reload nginx 2>/dev/null || service nginx reload 2>/dev/null || nginx -s reload)',
                    escapeshellarg($linkFile),
                    escapeshellarg($confFile)
                )
            ),
        ), 120);

        if (! preg_match('/DPLY_NGINX_REMOVE_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Nginx config cleanup failed. Output: '.Str::limit($out, 2000));
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['nginx_cleanup_output'] = $out;

        $site->update(['meta' => $meta]);

        return $out;
    }
}
