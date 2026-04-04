<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Sites\Contracts\SiteWebserverProvisioner;
use Illuminate\Support\Str;

class SiteApacheProvisioner extends AbstractSiteWebserverProvisioner implements SiteWebserverProvisioner
{
    public function __construct(
        protected ApacheSiteConfigBuilder $builder,
        ?SitePlaceholderPageBuilder $placeholderPageBuilder = null,
    ) {
        parent::__construct($placeholderPageBuilder);
    }

    public function webserver(): string
    {
        return 'apache';
    }

    public function provision(Site $site): string
    {
        $server = $this->ensureServerReady($site);
        $config = $this->builder->build($site);
        $available = rtrim(config('sites.apache_sites_available'), '/');
        $enabled = rtrim(config('sites.apache_sites_enabled'), '/');
        $confFile = $available.'/'.$this->configBasename($site).'.conf';
        $linkFile = $enabled.'/'.$this->configBasename($site).'.conf';

        $ssh = $this->systemSsh($site);
        $this->installPlaceholderPage($site, $ssh);
        $this->ensureSuspendedPage($site, $ssh);
        $this->syncBasicAuthHtpasswdFiles($site, $ssh);
        $this->writeSystemFile($ssh, $confFile, $config);
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_APACHE_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'a2enmod proxy proxy_fcgi rewrite headers >/dev/null 2>&1 || true && a2ensite %1$s >/dev/null 2>&1 && apachectl configtest && (systemctl reload apache2 2>/dev/null || service apache2 reload 2>/dev/null)',
                    escapeshellarg($this->configBasename($site).'.conf')
                )
            )
        ), 120);

        if (! preg_match('/DPLY_APACHE_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Apache config test or reload failed. Output: '.Str::limit($out, 2000));
        }

        $this->updateSiteMeta($site, 'apache_last_output', $out);

        return $out;
    }

    public function remove(Site $site): string
    {
        $server = $this->ensureServerReady($site);
        $available = rtrim(config('sites.apache_sites_available'), '/');
        $confFile = $available.'/'.$this->configBasename($site).'.conf';

        $ssh = $this->systemSsh($site);
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_APACHE_REMOVE_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'a2dissite %1$s >/dev/null 2>&1 || true && rm -f %2$s && apachectl configtest && (systemctl reload apache2 2>/dev/null || service apache2 reload 2>/dev/null)',
                    escapeshellarg($this->configBasename($site).'.conf'),
                    escapeshellarg($confFile)
                )
            )
        ), 120);

        if (! preg_match('/DPLY_APACHE_REMOVE_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Apache config cleanup failed. Output: '.Str::limit($out, 2000));
        }

        $this->updateSiteMeta($site, 'apache_cleanup_output', $out);

        return $out;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function readCurrentSiteConfig(Site $site): ?string
    {
        $server = $this->ensureServerReady($site);
        $ssh = $this->systemSsh($site);
        $available = rtrim(config('sites.apache_sites_available'), '/');
        $confFile = $available.'/'.$this->configBasename($site).'.conf';

        return $this->readRemoteFile($server, $ssh, $confFile);
    }

    public function validatePendingOnServer(Site $site, string $pendingConfig): array
    {
        $server = $this->ensureServerReady($site);
        $ssh = $this->systemSsh($site);
        $available = rtrim(config('sites.apache_sites_available'), '/');
        $confFile = $available.'/'.$this->configBasename($site).'.conf';
        $prevMain = $this->readRemoteFile($server, $ssh, $confFile);

        $ok = false;
        $message = '';

        try {
            $this->writeSystemFile($ssh, $confFile, $pendingConfig);
            $out = $ssh->exec(sprintf(
                '(%s) 2>&1; printf "\nDPLY_APACHE_TEST_EXIT:%%s" "$?"',
                $this->privilegedCommand($server, 'apachectl configtest')
            ), 120);
            $ok = (bool) preg_match('/DPLY_APACHE_TEST_EXIT:0\s*$/', $out);
            $message = trim($out);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        } finally {
            $this->restoreRemoteFile($ssh, $server, $confFile, $prevMain);
        }

        return [
            'ok' => $ok,
            'message' => $message !== '' ? $message : ($ok ? __('Apache configuration is valid.') : __('Apache validation failed.')),
        ];
    }
}
