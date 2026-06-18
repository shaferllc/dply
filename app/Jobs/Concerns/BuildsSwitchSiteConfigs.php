<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Modules\Certificates\Jobs\ExecuteSiteCertificateJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Modules\Certificates\Services\CertificateRequestService;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\SshConnection;
use App\Support\Servers\CaddyRuntimeOwnership;
use Illuminate\Database\Eloquent\Collection;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsSwitchSiteConfigs
{


    /**
     * Render a per-site config string for the target webserver. Dispatches to
     * the appropriate builder; listenPort=8080 binds each site hostname on
     * that port for validation (not a catch-all `:8080` block); listenPort=null
     * produces the production config.
     */
    private function buildSiteConfigFor(Site $site, string $target, ?int $listenPort): string
    {
        return match ($target) {
            'nginx' => app(NginxSiteConfigBuilder::class)->build($site, null, $listenPort),
            'caddy' => app(CaddySiteConfigBuilder::class)->build($site, $listenPort),
            'apache' => app(ApacheSiteConfigBuilder::class)->build($site, $listenPort),
            'openlitespeed' => app(OpenLiteSpeedSiteConfigBuilder::class)->build($site, $listenPort),
            default => throw new \RuntimeException(sprintf(
                'No config builder for "%s" — supported in v1: nginx, caddy, apache, openlitespeed.',
                $target,
            )),
        };
    }

    /**
     * Remote on-disk path for a site's config under the given webserver.
     */
    private function siteConfigPathFor(Site $site, string $target): string
    {
        $basename = $site->webserverConfigBasename();

        return match ($target) {
            'nginx' => '/etc/nginx/sites-available/'.$basename,
            'apache' => '/etc/apache2/sites-available/'.$basename.'.conf',
            'caddy' => '/etc/caddy/sites-enabled/'.$basename.'.caddy',
            'openlitespeed' => '/usr/local/lsws/conf/vhosts/'.$basename.'/vhconf.conf',
            default => throw new \RuntimeException('No config path mapping for '.$target),
        };
    }

    /**
     * Ensure the directories the target uses for per-site configs exist + the
     * sites-enabled directory (where applicable) is set up.
     */
    private function ensureTargetConfigDirs(Server $server, SshConnection $ssh): void
    {
        $cmd = match ($this->target) {
            'nginx' => 'mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled',
            'apache' => 'mkdir -p /etc/apache2/sites-available /etc/apache2/sites-enabled',
            'caddy' => 'mkdir -p /etc/caddy/sites-enabled /var/log/caddy && touch /etc/caddy/Caddyfile && (grep -Fq \'import /etc/caddy/sites-enabled/*.caddy\' /etc/caddy/Caddyfile || printf "\nimport /etc/caddy/sites-enabled/*.caddy\n" >> /etc/caddy/Caddyfile) && '.CaddyRuntimeOwnership::shell(),
            // OLS keeps per-vhost configs under conf/vhosts/<name>/vhconf.conf;
            // executeStageProvision writes the top-level httpd_config.conf
            // after the per-site loop via writeOlsHttpdConfig().
            'openlitespeed' => 'mkdir -p /usr/local/lsws/conf/vhosts',
            default => 'true',  // no-op for unsupported targets; preflight should have blocked earlier.
        };
        $ssh->exec($this->privilegedCommand($server, $cmd), 30);
    }

    /**
     * For nginx/apache: symlink the site config from sites-available → sites-enabled
     * so the webserver picks it up. Caddy uses the import-from-sites-enabled
     * pattern set up by ensureTargetConfigDirs(), so no per-site symlink is needed.
     */
    private function ensureSiteEnabled(Server $server, SshConnection $ssh, Site $site, string $target): void
    {
        $basename = $site->webserverConfigBasename();
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
            default => null,  // caddy: import-glob handles it.
        };
        if ($cmd !== null) {
            $ssh->exec($this->privilegedCommand($server, $cmd), 15);
        }
    }

    /**
     * Render and write the dply-owned `/usr/local/lsws/conf/httpd_config.conf`
     * bound to `$listenPort`. Stage 2 calls this with :8080 so `lshttpd -t`
     * parses a config that doesn't conflict with the live webserver on :80;
     * cutover calls it again with :80 to land the production listener before
     * service-swap.
     *
     * Backs up any pre-existing httpd_config.conf to `.dply-bak-<timestamp>`
     * the first time we write — a fresh `apt install openlitespeed` ships a
     * stock config with WebAdmin + Example vhosts that we don't want to keep,
     * but we preserve it for forensic recovery.
     */
    private function ensureCaddyRuntimeOwnership(Server $server, SshConnection $ssh): void
    {
        $ssh->exec($this->privilegedCommand($server, CaddyRuntimeOwnership::shell()), 30);
    }

    /**
     * Maps a webserver key to its systemd unit name. The mapping isn't 1:1 —
     * apache2 on Debian/Ubuntu, httpd on RHEL-family (dply targets Debian/Ubuntu).
     */
    private function systemdUnitFor(string $webserver): ?string
    {
        return match (strtolower($webserver)) {
            'nginx' => 'nginx',
            'caddy' => 'caddy',
            'apache' => 'apache2',
            'openlitespeed' => 'lshttpd',
            'traefik' => 'traefik',
            default => null,
        };
    }

    /**
     * Align site rows with the new server webserver and re-queue HTTP-01 TLS
     * installs when leaving Caddy auto-HTTPS or another certbot-backed engine.
     */
    protected function reconcileSitesAfterSwitch(Server $server, string $target, string $from): void
    {
        $target = strtolower(trim($target));
        $from = strtolower(trim($from));
        $activeStatus = Site::activeStatusForWebserver($target);

        $sites = Site::query()
            ->where('server_id', $server->id)
            ->with('previewDomains')
            ->get();

        foreach ($sites as $site) {
            $meta = $site->meta;
            if (isset($meta['provisioning']) && is_array($meta['provisioning'])) {
                $meta['provisioning']['webserver'] = $target;
            }

            $site->update([
                'status' => $activeStatus,
                'meta' => $meta,
            ]);
        }

        if ($target === 'caddy' && $this->tlsToCaddy) {
            return;
        }

        if (! in_array($target, ['nginx', 'apache', 'openlitespeed'], true)) {
            return;
        }

        if (! in_array($from, ['nginx', 'apache', 'caddy', 'openlitespeed'], true)) {
            return;
        }

        $siteIds = $sites->pluck('id');

        $certificates = SiteCertificate::query()
            ->whereIn('site_id', $siteIds)
            ->where('provider_type', SiteCertificate::PROVIDER_LETSENCRYPT)
            ->where('challenge_type', SiteCertificate::CHALLENGE_HTTP)
            ->whereIn('status', [
                SiteCertificate::STATUS_FAILED,
                SiteCertificate::STATUS_ACTIVE,
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_INSTALLING,
            ])
            ->get();

        foreach ($certificates as $certificate) {
            $certificate->update(['status' => SiteCertificate::STATUS_PENDING]);
            ExecuteSiteCertificateJob::dispatch($certificate->id, $this->userId);
        }

        $certificateRequestService = app(CertificateRequestService::class);
        foreach ($sites as $site) {
            $certificate = $certificateRequestService->queuePrimaryPreviewAutoSsl($site);
            if ($certificate !== null) {
                ExecuteSiteCertificateJob::dispatch($certificate->id, $this->userId);
            }
        }
    }
}
