<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\OpenLiteSpeedHttpdConfigBuilder;
use App\Services\Servers\OpenLiteSpeedHttpdConfigPreserver;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\SshConnection;
use App\Support\Servers\CaddyRuntimeOwnership;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared per-site config write helpers for webserver / edge-proxy jobs.
 */
trait WritesPerSiteWebserverConfigs
{
    protected function resolveEdgeProxyPreviousWebserver(Server $server): string
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $previous = strtolower(trim((string) ($meta['edge_proxy_previous_webserver'] ?? '')));
        if ($previous !== '' && in_array($previous, ['nginx', 'caddy', 'apache', 'openlitespeed'], true)) {
            return $previous;
        }

        $fallback = strtolower(trim((string) ($meta['webserver'] ?? 'nginx')));

        return in_array($fallback, ['nginx', 'caddy', 'apache', 'openlitespeed'], true) ? $fallback : 'nginx';
    }

    protected function buildSiteConfigFor(Site $site, string $target, ?int $listenPort): string
    {
        return match ($target) {
            'nginx' => app(NginxSiteConfigBuilder::class)->build($site, null, $listenPort),
            'caddy' => app(CaddySiteConfigBuilder::class)->build($site, $listenPort),
            'apache' => app(ApacheSiteConfigBuilder::class)->build($site, $listenPort),
            'openlitespeed' => app(OpenLiteSpeedSiteConfigBuilder::class)->build($site, $listenPort),
            default => throw new \RuntimeException(sprintf(
                'No config builder for "%s" — supported: nginx, caddy, apache, openlitespeed.',
                $target,
            )),
        };
    }

    protected function siteConfigPathFor(Site $site, string $target): string
    {
        $basename = $this->basenameForSite($site);

        return match ($target) {
            'nginx' => '/etc/nginx/sites-available/'.$basename,
            'apache' => '/etc/apache2/sites-available/'.$basename.'.conf',
            'caddy' => '/etc/caddy/sites-enabled/'.$basename.'.caddy',
            'openlitespeed' => '/usr/local/lsws/conf/vhosts/'.$basename.'/vhconf.conf',
            default => throw new \RuntimeException('No config path mapping for '.$target),
        };
    }

    protected function ensureTargetConfigDirs(Server $server, SshConnection $ssh, string $target): void
    {
        $cmd = match ($target) {
            'nginx' => 'mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled',
            'apache' => 'mkdir -p /etc/apache2/sites-available /etc/apache2/sites-enabled',
            'caddy' => 'mkdir -p /etc/caddy/sites-enabled /var/log/caddy && touch /etc/caddy/Caddyfile && (grep -Fq \'import /etc/caddy/sites-enabled/*.caddy\' /etc/caddy/Caddyfile || printf "\nimport /etc/caddy/sites-enabled/*.caddy\n" >> /etc/caddy/Caddyfile) && '.CaddyRuntimeOwnership::shell(),
            'openlitespeed' => 'mkdir -p /usr/local/lsws/conf/vhosts',
            default => 'true',
        };
        $ssh->exec($this->privilegedCommand($server, $cmd), 30);
    }

    protected function ensureSiteEnabled(Server $server, SshConnection $ssh, Site $site, string $target): void
    {
        $basename = $this->basenameForSite($site);
        $cmd = match ($target) {
            'nginx' => sprintf(
                'ln -sf %s %s',
                escapeshellarg('/etc/nginx/sites-available/'.$basename),
                escapeshellarg('/etc/nginx/sites-enabled/'.$basename),
            ),
            'apache' => sprintf(
                'ln -sf %s %s',
                escapeshellarg('/etc/apache2/sites-available/'.$basename.'.conf'),
                escapeshellarg('/etc/apache2/sites-enabled/'.$basename.'.conf'),
            ),
            default => null,
        };
        if ($cmd !== null) {
            $ssh->exec($this->privilegedCommand($server, $cmd), 15);
        }
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    protected function writeOlsHttpdConfig(
        Server $server,
        SshConnection $ssh,
        Collection $sites,
        int $listenPort,
    ): void {
        $path = '/usr/local/lsws/conf/httpd_config.conf';
        $backupCmd = sprintf(
            '[ -f %1$s ] && [ ! -f %1$s.dply-bak ] && cp %1$s %1$s.dply-bak || true',
            escapeshellarg($path),
        );
        $ssh->exec($this->privilegedCommand($server, $backupCmd), 15);

        $contents = app(OpenLiteSpeedHttpdConfigBuilder::class)->build($sites, $listenPort);
        $existing = $ssh->exec('sudo -n cat '.escapeshellarg($path).' 2>/dev/null', 15);
        if ($existing !== '' && $ssh->lastExecExitCode() === 0) {
            $contents = app(OpenLiteSpeedHttpdConfigPreserver::class)->merge($contents, $existing);
        }
        $this->writeRemoteFile($server, $ssh, $path, $contents);
    }

    protected function ensureCaddyRuntimeOwnership(Server $server, SshConnection $ssh): void
    {
        $ssh->exec($this->privilegedCommand($server, CaddyRuntimeOwnership::shell()), 30);
    }

    protected function validateWebserverConfig(Server $server, string $webserver): void
    {
        $cmd = match ($webserver) {
            'nginx' => 'nginx -t',
            'caddy' => CaddyRuntimeOwnership::validateCommand(),
            'apache' => 'apachectl configtest',
            'openlitespeed' => '/usr/local/lsws/bin/lshttpd -t',
            default => throw new \RuntimeException('No config-test command for '.$webserver),
        };

        $ssh = new SshConnection($server);
        $out = $ssh->exec($this->privilegedCommand($server, $cmd.' 2>&1'), 60);
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(sprintf(
                '%s config-test failed (exit %d): %s',
                $webserver,
                $exit,
                trim(substr((string) $out, -500)),
            ));
        }

        if ($webserver === 'caddy') {
            $this->ensureCaddyRuntimeOwnership($server, $ssh);
        }
    }

    protected function systemdUnitForWebserver(string $webserver): ?string
    {
        return match (strtolower($webserver)) {
            'nginx' => 'nginx',
            'caddy' => 'caddy',
            'apache' => 'apache2',
            'openlitespeed' => 'lshttpd',
            'traefik' => 'traefik',
            'haproxy' => 'haproxy',
            'envoy' => 'envoy',
            default => null,
        };
    }

    protected function waitForPortFree(Server $server, SshConnection $ssh, int $port): void
    {
        $check = sprintf('ss -ltn -H "sport = :%d" 2>/dev/null | head -n 1', $port);
        $deadline = microtime(true) + 10.0;
        do {
            $out = trim($ssh->exec($check, 5));
            if ($out === '') {
                return;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        $ssh->exec($this->privilegedCommand($server, sprintf('fuser -k -TERM %d/tcp 2>/dev/null || true', $port)), 5);
        usleep(500_000);
    }

    protected function basenameForSite(Site $site): string
    {
        return method_exists($site, 'webserverConfigBasename')
            ? (string) $site->webserverConfigBasename()
            : (string) $site->slug;
    }
}
