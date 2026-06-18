<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\SshConnection;
use App\Support\Sites\OpenLiteSpeedTlsPaths;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rewrites dply-owned OpenLiteSpeed httpd + vhconf files after TLS material
 * lands (webserver switch or certbot certonly). Without this, OLS only serves
 * plain HTTP on :80 even when /etc/letsencrypt/live/* exists from a prior engine.
 */
class OpenLiteSpeedTlsConfigurator
{
    use PrivilegedRemoteFileWrites;

    private const HTTPD_PATH = '/usr/local/lsws/conf/httpd_config.conf';

    public function syncServer(Server $server): void
    {
        if (strtolower(trim((string) ($server->meta['webserver'] ?? ''))) !== 'openlitespeed') {
            return;
        }

        $sites = Site::query()
            ->where('server_id', $server->id)
            ->with(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers', 'previewDomains', 'server'])
            ->get();

        if ($sites->isEmpty()) {
            return;
        }

        $ssh = new SshConnection($server);

        $listenerTls = $this->resolveListenerTlsMaterialFromSsh($ssh, $sites);

        foreach ($sites as $site) {
            $basename = $site->webserverConfigBasename();
            $repo = rtrim($site->effectiveRepositoryPath(), '/');
            $ssh->exec($this->privilegedCommand(
                $server,
                sprintf(
                    'mkdir -p %s %s',
                    escapeshellarg('/usr/local/lsws/conf/vhosts/'.$basename),
                    escapeshellarg($repo.'/logs'),
                ),
            ), 15);

            $config = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site, listenPort: null);
            $path = '/usr/local/lsws/conf/vhosts/'.$basename.'/vhconf.conf';
            $this->writeRemoteFile($server, $ssh, $path, $config);
        }

        $httpd = app(OpenLiteSpeedHttpdConfigBuilder::class)->build($sites, listenPort: 80, listenerTls: $listenerTls);
        $this->writeRemoteFile($server, $ssh, self::HTTPD_PATH, $httpd);

        $test = trim($ssh->exec(
            $this->privilegedCommand($server, '/usr/local/lsws/bin/lshttpd -t 2>&1'),
            60,
        ));
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException('OpenLiteSpeed config-test failed after TLS sync: '.mb_substr($test, -500));
        }

        $ssh->exec($this->privilegedCommand($server, 'systemctl reload lshttpd 2>&1 || systemctl restart lshttpd 2>&1'), 60);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveListenerTlsMaterial(Server $server, Collection $sites): ?array
    {
        return $this->resolveListenerTlsMaterialFromSsh(new SshConnection($server), $sites);
    }

    /**
     * Pick the first site whose LE material is present on disk for the
     * DefaultSsl listener fallback cert (required for :443 to bind).
     *
     * @param  Collection<int, Site>  $sites
     * @return array{keyFile: string, certFile: string}|null
     */
    private function resolveListenerTlsMaterialFromSsh(SshConnection $ssh, Collection $sites): ?array
    {
        foreach ($sites as $site) {
            $paths = OpenLiteSpeedTlsPaths::resolve($site);
            if ($paths === null) {
                continue;
            }

            $check = trim($ssh->exec(sprintf(
                'sudo -n test -f %s -a -f %s && echo ok || echo missing',
                escapeshellarg($paths['certFile']),
                escapeshellarg($paths['keyFile']),
            ), 10));

            if ($check === 'ok') {
                return $paths;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    public function buildHttpdConfig(Collection $sites, int $listenPort): string
    {
        return app(OpenLiteSpeedHttpdConfigBuilder::class)->build($sites, $listenPort);
    }
}
