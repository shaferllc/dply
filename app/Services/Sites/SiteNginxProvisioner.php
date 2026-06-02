<?php

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Models\ConsoleAction;
use App\Models\ServerWebserverCacheFeature;
use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\Contracts\SiteWebserverProvisioner;
use App\Services\SshConnection;
use App\Support\Servers\CaddyEdgeBackendLayout;
use App\Support\Servers\NginxServiceScript;
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

    public function provision(Site $site, ?ConsoleEmitter $emit = null): string
    {
        $emit ??= new ConsoleEmitter;

        $emit->step('nginx', 'resolving server connection');
        $server = $this->ensureServerReady($site);

        $profile = SiteWebserverConfigProfile::query()->firstOrCreate(
            ['site_id' => $site->id],
            [
                'webserver' => 'nginx',
                'mode' => SiteWebserverConfigProfile::MODE_LAYERED,
                'main_snippet_body' => $site->nginx_extra_raw,
            ]
        );
        $site->setRelation('webserverConfigProfile', $profile);

        $config = $this->builder->build($site, $profile);
        $available = rtrim(config('sites.nginx_sites_available'), '/');
        $enabled = rtrim(config('sites.nginx_sites_enabled'), '/');
        $confFile = $available.'/'.$this->configBasename($site).'.conf';
        $linkFile = $enabled.'/'.$this->configBasename($site).'.conf';

        $ssh = $this->systemSsh($site);

        // First-apply only — nginx_installed_at flips on the first successful
        // provision, so subsequent applies (rotations, SSL, etc.) never even
        // probe the doc root for a placeholder.
        if ($site->nginx_installed_at === null) {
            $this->installPlaceholderPage($site, $ssh, $emit);
        }
        $this->ensureSuspendedPage($site, $ssh, $emit);
        $this->ensureManagedErrorPages($site, $ssh, $emit);
        $this->ensureNginxEngineHttpCacheInfrastructure($site, $ssh, $emit);
        $this->syncBasicAuthHtpasswdFiles($site, $ssh, $emit);
        $this->syncAccessGateFiles($site, $ssh, $emit);
        $this->writeNginxLayerSnippetFiles($site, $profile, $ssh, $emit);

        // Vhost write only emits when content actually changed; an apply that
        // didn't touch anything the vhost references (e.g. a sync that found
        // nothing) produces no banner line here either.
        if ($this->writeSystemFileIfChanged($server, $ssh, $confFile, $config)) {
            $emit->step('nginx', 'writing site config file: '.$confFile);
        }

        $emit->step('nginx', 'running nginx -t and applying config');
        $nginxApply = sprintf(
            'ln -sf %1$s %2$s && %3$s',
            escapeshellarg($confFile),
            escapeshellarg($linkFile),
            NginxServiceScript::testAndReloadOrStartScript(),
        );
        if (! $server->hasEdgeProxy()) {
            $nginxApply = CaddyEdgeBackendLayout::releasePort80Shell()."\n".$nginxApply;
        }
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_NGINX_EXIT:%%s" "$?"',
            $this->privilegedCommand($server, $nginxApply),
        ), 120);

        // The nginx -t output is multi-line; emit each non-blank line so the
        // console pane shows them as separate entries instead of one giant blob.
        foreach (preg_split('/\r\n|\r|\n/', trim($out)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $emit($line, ConsoleAction::LEVEL_INFO, 'nginx');
        }

        if (! preg_match('/DPLY_NGINX_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Nginx test or reload failed. Output: '.Str::limit($out, 2000));
        }

        $emit->success('reload OK', 'nginx');

        $site->update([
            'nginx_installed_at' => now(),
            'meta' => array_merge($site->meta ?? [], ['nginx_last_output' => $out]),
        ]);

        return $out;
    }

    /**
     * Temporarily writes pending main + layer files, runs nginx -t, then restores previous files.
     *
     * @return array{ok: bool, message: string}
     */
    public function readCurrentMainConfig(Site $site): ?string
    {
        $server = $this->ensureServerReady($site);
        $ssh = $this->systemSsh($site);
        $available = rtrim(config('sites.nginx_sites_available'), '/');
        $confFile = $available.'/'.$this->configBasename($site).'.conf';

        return $this->readRemoteFile($server, $ssh, $confFile);
    }

    /**
     * Reads a layered snippet file from the host (before or after include target).
     */
    public function readLayerSnippetFile(Site $site, string $which): ?string
    {
        $server = $this->ensureServerReady($site);
        $ssh = $this->systemSsh($site);
        $basename = $this->configBasename($site);
        $base = rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename;
        $sub = $which === 'before' ? 'before/10-dply-layer.conf' : 'after/10-dply-layer.conf';
        $path = $base.'/'.$sub;

        return $this->readRemoteFile($server, $ssh, $path);
    }

    /**
     * Extracts the main layered snippet from a live vhost file (content inside the server block before the after-layer include).
     */
    public function parseLayeredMainSnippetFromVhost(Site $site, string $mainConfig): ?string
    {
        $basename = $this->configBasename($site);
        $base = rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename;
        if (! str_contains($mainConfig, $base.'/before/') || ! str_contains($mainConfig, $base.'/after/')) {
            return null;
        }

        $escapedBase = preg_quote($base, '#');
        if (! preg_match(
            '#\n    include\s+'.$escapedBase.'/after/\*\.conf;\s*\R#',
            $mainConfig,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $includePos = $m[0][1];
        $beforeInclude = substr($mainConfig, 0, $includePos);
        $snippetChunk = $this->stripPrefixBeforeLayeredMainSnippet($site, $beforeInclude);

        return $this->unindentLeadingFourSpaces(trim($snippetChunk));
    }

    protected function stripPrefixBeforeLayeredMainSnippet(Site $site, string $beforeIncludeChunk): string
    {
        $chunk = str_replace("\r\n", "\n", $beforeIncludeChunk);

        if ($site->type === SiteType::Node) {
            $marker = "    location / {\n";
            $pos = strrpos($chunk, $marker);
            if ($pos !== false) {
                $rest = substr($chunk, $pos);
                if (preg_match('/^    location \/ \{[\s\S]*?\n    \}\n/', $rest, $m)) {
                    return substr($rest, strlen($m[0]));
                }
            }

            return $beforeIncludeChunk;
        }

        $denyMarker = "    location ~ /\.(?!well-known).* {\n        deny all;\n    }\n";
        $pos = strrpos($chunk, $denyMarker);
        if ($pos !== false) {
            return substr($chunk, $pos + strlen($denyMarker));
        }

        return $beforeIncludeChunk;
    }

    protected function unindentLeadingFourSpaces(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $out = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '    ')) {
                $out[] = substr($line, 4);
            } else {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    public function validatePendingOnServer(Site $site, string $pendingMainConfig, SiteWebserverConfigProfile $profile): array
    {
        $server = $this->ensureServerReady($site);
        $ssh = $this->systemSsh($site);
        $available = rtrim(config('sites.nginx_sites_available'), '/');
        $confFile = $available.'/'.$this->configBasename($site).'.conf';
        $basename = $this->configBasename($site);
        $base = rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename;
        $beforeFile = $base.'/before/10-dply-layer.conf';
        $afterFile = $base.'/after/10-dply-layer.conf';

        $prevMain = $this->readRemoteFile($server, $ssh, $confFile);
        $prevBefore = $this->readRemoteFile($server, $ssh, $beforeFile);
        $prevAfter = $this->readRemoteFile($server, $ssh, $afterFile);

        $ok = false;
        $message = '';

        try {
            $this->writeSystemFile($ssh, $confFile, $pendingMainConfig);
            $this->writeNginxLayerSnippetFiles($site, $profile, $ssh);
            $out = $ssh->exec(sprintf(
                '(%s) 2>&1; printf "\nDPLY_NGINX_TEST_EXIT:%%s" "$?"',
                $this->privilegedCommand($server, 'nginx -t')
            ), 120);
            $ok = (bool) preg_match('/DPLY_NGINX_TEST_EXIT:0\s*$/', $out);
            $message = trim($out);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        } finally {
            $this->restoreRemoteFile($ssh, $server, $confFile, $prevMain);
            $this->restoreRemoteFile($ssh, $server, $beforeFile, $prevBefore);
            $this->restoreRemoteFile($ssh, $server, $afterFile, $prevAfter);
        }

        return [
            'ok' => $ok,
            'message' => $message !== '' ? $message : ($ok ? __('Nginx configuration is valid.') : __('Nginx validation failed.')),
        ];
    }

    /**
     * Ensures per-site layer directories exist under {@see config('sites.nginx_dply_site_path')}.
     */
    protected function ensureNginxLayerDirectories(Site $site, SshConnection $ssh): void
    {
        $server = $site->server;
        if ($server === null) {
            return;
        }

        $base = rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$this->configBasename($site);
        $cmd = sprintf(
            'mkdir -p %s %s %s',
            escapeshellarg($base),
            escapeshellarg($base.'/before'),
            escapeshellarg($base.'/after')
        );
        $ssh->exec($this->privilegedCommand($server, $cmd), 30);
    }

    /**
     * Creates missing before/after snippet files on the host (e.g. legacy sites) so includes resolve.
     */
    public function ensureNginxLayerSnippetFilesIfMissing(Site $site, SiteWebserverConfigProfile $profile): void
    {
        if ($profile->mode !== SiteWebserverConfigProfile::MODE_LAYERED) {
            return;
        }

        $server = $this->ensureServerReady($site);
        $ssh = $this->systemSsh($site);
        $basename = $this->configBasename($site);
        $base = rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename;
        $beforeFile = $base.'/before/10-dply-layer.conf';
        $afterFile = $base.'/after/10-dply-layer.conf';

        $this->ensureNginxLayerDirectories($site, $ssh);

        if ($this->readRemoteFile($server, $ssh, $beforeFile) === null) {
            $beforeBody = trim((string) $profile->before_body);
            $this->writeSystemFile(
                $ssh,
                $beforeFile,
                $beforeBody !== '' ? $beforeBody : "# Dply placeholder (empty before layer)\n"
            );
        }

        if ($this->readRemoteFile($server, $ssh, $afterFile) === null) {
            $afterBody = trim((string) $profile->after_body);
            $this->writeSystemFile(
                $ssh,
                $afterFile,
                $afterBody !== '' ? $afterBody : "# Dply placeholder (empty after layer)\n"
            );
        }
    }

    /**
     * Writes before/after snippet files for layered nginx profiles so include globs always match at least one file.
     * Emits a `step` only when at least one snippet actually changed.
     */
    protected function writeNginxLayerSnippetFiles(Site $site, SiteWebserverConfigProfile $profile, SshConnection $ssh, ?ConsoleEmitter $emit = null): void
    {
        if ($profile->mode !== SiteWebserverConfigProfile::MODE_LAYERED) {
            return;
        }

        $this->ensureNginxLayerDirectories($site, $ssh);

        $server = $site->server;
        if ($server === null) {
            return;
        }

        $basename = $this->configBasename($site);
        $base = rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename;
        $beforeDir = $base.'/before';
        $afterDir = $base.'/after';
        $beforeBody = trim((string) $profile->before_body);
        $afterBody = trim((string) $profile->after_body);
        $beforeContent = $beforeBody !== '' ? $beforeBody : "# Dply placeholder (empty before layer)\n";
        $afterContent = $afterBody !== '' ? $afterBody : "# Dply placeholder (empty after layer)\n";

        $changed = $this->writeSystemFileIfChanged($server, $ssh, $beforeDir.'/10-dply-layer.conf', $beforeContent);
        $changed = $this->writeSystemFileIfChanged($server, $ssh, $afterDir.'/10-dply-layer.conf', $afterContent) || $changed;

        if ($changed) {
            $emit?->step('nginx', 'updating layer snippet files');
        }
    }

    /**
     * Shared {@see fastcgi_cache_path} / {@see proxy_cache_path} zones for engine HTTP cache (referenced by site vhosts).
     * Emits a `step` only when the conf file actually had to be (re)written —
     * a steady-state apply (no cache-config change) produces no banner line.
     */
    protected function ensureNginxEngineHttpCacheInfrastructure(Site $site, SshConnection $ssh, ?ConsoleEmitter $emit = null): void
    {
        $server = $site->server;
        if ($server === null) {
            return;
        }

        $confPath = config('sites.nginx_engine_http_cache_conf');
        $fcgiPath = config('sites.nginx_engine_fcgi_cache_path');
        $proxyPath = config('sites.nginx_engine_proxy_cache_path');
        $fcgiZone = config('sites.nginx_engine_fcgi_cache_zone');
        $proxyZone = config('sites.nginx_engine_proxy_cache_zone');

        // Zone sizes live on `server_webserver_cache_features` so operators
        // can tune them per-server from the workspace. The row is created
        // lazily here with the legacy defaults (100m/100m/2g/60m) so existing
        // servers get the same on-disk output until someone touches it.
        $feature = ServerWebserverCacheFeature::findOrCreateFor(
            $server->id,
            ServerWebserverCacheFeature::WEBSERVER_NGINX,
        );
        $fcgiSize = (int) $feature->nginx_fcgi_zone_size_mb;
        $proxySize = (int) $feature->nginx_proxy_zone_size_mb;
        $maxSize = (int) $feature->nginx_zone_max_size_gb;
        $inactive = (int) $feature->nginx_zone_inactive_minutes;

        $contents = "# Managed by Dply — shared HTTP cache zones (do not edit by hand)\n";
        $contents .= "fastcgi_cache_path {$fcgiPath} levels=1:2 keys_zone={$fcgiZone}:{$fcgiSize}m inactive={$inactive}m max_size={$maxSize}g;\n";
        $contents .= "proxy_cache_path {$proxyPath} levels=1:2 keys_zone={$proxyZone}:{$proxySize}m inactive={$inactive}m max_size={$maxSize}g;\n";

        $wrote = $this->writeSystemFileIfChanged($server, $ssh, $confPath, $contents);
        if ($wrote) {
            $emit?->step('nginx', 'updating engine HTTP cache config');
        }

        $cmd = sprintf(
            'mkdir -p %s %s && (chown -R www-data:www-data %s %s 2>/dev/null || chown -R nginx:nginx %s %s 2>/dev/null || true)',
            escapeshellarg($fcgiPath),
            escapeshellarg($proxyPath),
            escapeshellarg($fcgiPath),
            escapeshellarg($proxyPath),
            escapeshellarg($fcgiPath),
            escapeshellarg($proxyPath)
        );

        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_NGX_CACHE_PREP_EXIT:%%s" "$?"',
            $this->privilegedCommand($server, $cmd)
        ), 60);

        if (! preg_match('/DPLY_NGX_CACHE_PREP_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Could not prepare nginx engine cache directories. Output: '.Str::limit($out, 1000));
        }
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
                    'rm -f %1$s %2$s && %3$s',
                    escapeshellarg($linkFile),
                    escapeshellarg($confFile),
                    NginxServiceScript::testAndReloadOrStartScript(),
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
