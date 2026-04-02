<?php

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Models\Site;

class NginxSiteConfigBuilder
{
    public function build(Site $site): string
    {
        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains', 'redirects']);
        $hostnames = collect($site->webserverHostnames());
        if ($hostnames->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before installing Nginx.');
        }

        $names = $hostnames->implode(' ');
        $basename = $site->nginxConfigBasename();

        if ($site->isSuspended()) {
            return $this->suspendedBlock($site, $basename, $names);
        }

        $root = $site->effectiveDocumentRootForNginx();
        $phpSock = str_replace(
            '{version}',
            $site->php_version ?? '8.3',
            config('sites.php_fpm_socket')
        );

        $redirects = $site->redirects->sortBy('sort_order')->values();
        $redirectBlock = '';
        foreach ($redirects as $r) {
            $from = $this->sanitizeLocationPath((string) $r->from_path);
            $to = $this->escapeNginxDoubleQuoted((string) $r->to_url);
            $code = (int) $r->status_code;
            if ($from !== '' && $to !== '' && in_array($code, [301, 302, 307, 308], true)) {
                $redirectBlock .= "    location = {$from} { return {$code} \"{$to}\"; }\n";
            }
        }

        $extra = trim((string) ($site->nginx_extra_raw ?? ''));
        $extraBlock = $extra !== '' ? "\n    ".$extra."\n" : '';

        $site->loadMissing('server');
        $poolUser = $site->server ? $site->effectiveSystemUser($site->server) : trim((string) ($site->php_fpm_user ?? ''));
        $poolNote = $poolUser !== ''
            ? "\n    # php-fpm pool user (configure pool on server): {$poolUser}\n"
            : '';

        return match ($site->type) {
            SiteType::Php => $this->phpBlock($basename, $names, $root, $phpSock, $redirectBlock, $extraBlock, $poolNote, $site),
            SiteType::Static => $this->staticBlock($basename, $names, $root, $redirectBlock, $extraBlock),
            SiteType::Node => $this->nodeBlock($basename, $names, (int) ($site->app_port ?? 3000), $redirectBlock, $extraBlock),
        };
    }

    /**
     * Static-only vhost serving {@see Site::suspendedStaticRoot()} (no PHP, proxy, or redirects).
     */
    protected function suspendedBlock(Site $site, string $basename, string $serverNames): string
    {
        $root = $site->suspendedStaticRoot();

        return <<<NGINX
# Managed by Dply — {$basename} (suspended)
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    root {$root};
    index index.html;
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    protected function phpBlock(
        string $basename,
        string $serverNames,
        string $root,
        string $phpSock,
        string $redirectBlock,
        string $extraBlock,
        string $poolNote,
        Site $site
    ): string {
        if ($site->octane_port) {
            $port = (int) $site->octane_port;
            $reverb = $this->reverbProxyLocationBlock($site);

            return <<<NGINX
# Managed by Dply — {$basename} (Laravel Octane)
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    root {$root};
    index index.php index.html;
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;
{$poolNote}{$redirectBlock}{$reverb}    location / {
        try_files \$uri @octane;
    }

    location @octane {
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_pass http://127.0.0.1:{$port};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
{$extraBlock}
}
NGINX;
        }

        $reverbPlain = $this->reverbProxyLocationBlock($site);

        return <<<NGINX
# Managed by Dply — {$basename}
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    root {$root};
    index index.php index.html;
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
{$poolNote}{$redirectBlock}{$reverbPlain}    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{$phpSock};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
{$extraBlock}
}
NGINX;
    }

    protected function staticBlock(
        string $basename,
        string $serverNames,
        string $root,
        string $redirectBlock,
        string $extraBlock
    ): string {
        return <<<NGINX
# Managed by Dply — {$basename}
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    root {$root};
    index index.html;
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;
{$redirectBlock}
    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
{$extraBlock}
}
NGINX;
    }

    protected function nodeBlock(
        string $basename,
        string $serverNames,
        int $port,
        string $redirectBlock,
        string $extraBlock
    ): string {
        return <<<NGINX
# Managed by Dply — {$basename}
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;
{$redirectBlock}
    location / {
        proxy_pass http://127.0.0.1:{$port};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
{$extraBlock}
}
NGINX;
    }

    protected function reverbProxyLocationBlock(Site $site): string
    {
        if (! $site->shouldProxyReverbInWebserver()) {
            return '';
        }

        $port = $site->reverbLocalPort();
        $loc = $site->reverbWebSocketPath();

        return <<<NGINX
    location ^~ {$loc} {
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_pass http://127.0.0.1:{$port};
    }

NGINX;
    }

    protected function sanitizeLocationPath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        if (! preg_match('#^/[a-zA-Z0-9/_\-]+$#', $path)) {
            return '';
        }

        return $path;
    }

    protected function escapeNginxDoubleQuoted(string $url): string
    {
        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $url);
    }
}
