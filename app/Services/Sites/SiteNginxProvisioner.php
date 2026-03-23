<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;
use Illuminate\Support\Str;

class SiteNginxProvisioner
{
    public function __construct(
        protected NginxSiteConfigBuilder $builder
    ) {}

    public function provision(Site $site): string
    {
        $server = $site->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $config = $this->builder->build($site);
        $available = rtrim(config('sites.nginx_sites_available'), '/');
        $enabled = rtrim(config('sites.nginx_sites_enabled'), '/');
        $confFile = $available.'/'.$site->nginxConfigBasename().'.conf';
        $linkFile = $enabled.'/'.$site->nginxConfigBasename().'.conf';

        $ssh = new SshConnection($server);
        $ssh->putFile($confFile, $config);
        $out = $ssh->exec(sprintf(
            '(ln -sf %1$s %2$s && nginx -t && (systemctl reload nginx 2>/dev/null || service nginx reload 2>/dev/null || nginx -s reload)) 2>&1; printf "\nDPLY_NGINX_EXIT:%%s" "$?"',
            escapeshellarg($confFile),
            escapeshellarg($linkFile)
        ), 120);

        $nginxOk = (bool) preg_match('/DPLY_NGINX_EXIT:0\s*$/', $out);
        if (! $nginxOk) {
            throw new \RuntimeException('Nginx test or reload failed. Output: '.Str::limit($out, 2000));
        }

        $site->update([
            'status' => Site::STATUS_NGINX_ACTIVE,
            'nginx_installed_at' => now(),
            'meta' => array_merge($site->meta ?? [], ['nginx_last_output' => $out]),
        ]);

        return $out;
    }
}
