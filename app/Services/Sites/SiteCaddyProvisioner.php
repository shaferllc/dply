<?php

namespace App\Services\Sites;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\Contracts\SiteWebserverProvisioner;
use App\Support\Servers\CaddyRuntimeOwnership;
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

    public function provision(Site $site, ?ConsoleEmitter $emit = null): string
    {
        $emit ??= new ConsoleEmitter;

        $emit->step('caddy', 'resolving server connection');
        $server = $this->ensureServerReady($site);

        $config = $this->builder->build($site);
        $configFile = rtrim(config('sites.caddy_sites_enabled'), '/').'/'.$this->configBasename($site).'.caddy';
        $importLine = 'import /etc/caddy/sites-enabled/*.caddy';

        $ssh = $this->systemSsh($site);
        // First-apply only — meta.caddy_last_output is written at the end of the
        // first successful provision, so subsequent applies skip the placeholder
        // probe entirely.
        if (! isset(($site->meta ?? [])['caddy_last_output'])) {
            $this->installPlaceholderPage($site, $ssh, $emit);
        }
        $this->ensureSuspendedPage($site, $ssh, $emit);
        $this->ensureWorkerPage($site, $ssh, $emit);
        $this->ensureManagedErrorPages($site, $ssh, $emit);
        $this->syncBasicAuthHtpasswdFiles($site, $ssh, $emit);
        $this->syncAccessGateFiles($site, $ssh, $emit);
        if ($this->writeSystemFileIfChanged($server, $ssh, $configFile, $config)) {
            $emit->step('caddy', 'writing site config: '.$configFile);
        }
        $emit->step('caddy', 'running caddy validate and reloading');
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_CADDY_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'mkdir -p /etc/caddy/sites-enabled /var/log/caddy && touch /etc/caddy/Caddyfile && (grep -Fqx %1$s /etc/caddy/Caddyfile || printf "\n%%s\n" %2$s >> /etc/caddy/Caddyfile) && %3$s && %4$s && (systemctl is-active --quiet caddy && systemctl reload caddy 2>/dev/null || systemctl restart caddy 2>/dev/null || service caddy restart 2>/dev/null)',
                    escapeshellarg($importLine),
                    escapeshellarg($importLine),
                    CaddyRuntimeOwnership::shell(),
                    CaddyRuntimeOwnership::validateCommand(),
                )
            )
        ), 120);

        foreach (preg_split('/\r\n|\r|\n/', trim($out)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $emit($line, ConsoleAction::LEVEL_INFO, 'caddy');
        }

        if (! preg_match('/DPLY_CADDY_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Caddy validate or reload failed. Output: '.Str::limit($out, 2000));
        }

        $emit->success('reload OK', 'caddy');

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

    /**
     * @return array{ok: bool, message: string}
     */
    public function readCurrentSiteConfig(Site $site): ?string
    {
        $server = $this->ensureServerReady($site);
        $ssh = $this->systemSsh($site);
        $configFile = rtrim(config('sites.caddy_sites_enabled'), '/').'/'.$this->configBasename($site).'.caddy';

        return $this->readRemoteFile($server, $ssh, $configFile);
    }

    public function validatePendingOnServer(Site $site, string $pendingConfig): array
    {
        $server = $this->ensureServerReady($site);
        $ssh = $this->systemSsh($site);
        $configFile = rtrim(config('sites.caddy_sites_enabled'), '/').'/'.$this->configBasename($site).'.caddy';
        $prev = $this->readRemoteFile($server, $ssh, $configFile);

        $ok = false;
        $message = '';

        try {
            $this->writeSystemFile($ssh, $configFile, $pendingConfig);
            $out = $ssh->exec(sprintf(
                '(%s) 2>&1; printf "\nDPLY_CADDY_TEST_EXIT:%%s" "$?"',
                $this->privilegedCommand(
                    $server,
                    'mkdir -p /etc/caddy/sites-enabled /var/log/caddy && touch /etc/caddy/Caddyfile && caddy validate --config /etc/caddy/Caddyfile'
                )
            ), 120);
            $ok = (bool) preg_match('/DPLY_CADDY_TEST_EXIT:0\s*$/', $out);
            $message = trim($out);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        } finally {
            $this->restoreRemoteFile($ssh, $server, $configFile, $prev);
        }

        return [
            'ok' => $ok,
            'message' => $message !== '' ? $message : ($ok ? __('Caddy configuration is valid.') : __('Caddy validation failed.')),
        ];
    }
}
