<?php

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerWebserverCacheFeature;
use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\Contracts\SiteWebserverProvisioner;
use App\Services\SshConnection;
use App\Support\Servers\CaddyEdgeBackendLayout;
use App\Support\Servers\NginxServiceScript;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SiteNginxProvisioner extends AbstractSiteWebserverProvisioner implements SiteWebserverProvisioner
{
    public function __construct(
        protected NginxSiteConfigBuilder $builder,
        ?SitePlaceholderPageBuilder $placeholderPageBuilder = null,
        protected NginxConfigGuard $guard = new NginxConfigGuard,
    ) {
        parent::__construct($placeholderPageBuilder);
    }

    protected function errorPageReferenceInjected(): bool
    {
        return true;
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
                'main_snippet_body' => trim((string) $site->nginx_extra_raw) !== ''
                    ? $site->nginx_extra_raw
                    : SiteWebserverConfigProfile::DEFAULT_MAIN_SNIPPET_BODY,
                'before_body' => SiteWebserverConfigProfile::DEFAULT_BEFORE_BODY,
                'after_body' => SiteWebserverConfigProfile::DEFAULT_AFTER_BODY,
            ]
        );
        $site->setRelation('webserverConfigProfile', $profile);

        $config = $this->builder->build($site, $profile);
        // Stamp ownership so a later read-back can tell a dply-written vhost from
        // a hand-authored one. The marker is a comment, so it never affects the
        // directive-level diff the guard performs.
        $config = $this->guard->stamp($config, $this->configBasename($site));
        $available = rtrim(config('sites.nginx_sites_available'), '/');
        $enabled = rtrim(config('sites.nginx_sites_enabled'), '/');
        $confFile = $available.'/'.$this->configBasename($site).'.conf';
        $linkFile = $enabled.'/'.$this->configBasename($site).'.conf';

        $ssh = $this->systemSsh($site);

        // Ensure the "being set up" splash whenever the site has no deployed
        // release yet — first apply (nginx_installed_at null) OR any apply for a
        // site that has never deployed (last_deploy_at null). A services-first
        // bare site lives in that state until its first deploy, and a botched
        // first provision can flip nginx_installed_at without ever landing the
        // placeholder, leaving an empty doc root that nginx 403s on. Once the
        // site has deployed, steady-state applies (rotations, SSL) skip the
        // probe. installPlaceholderPage() is idempotent — it leaves a real
        // release's index untouched — so this never clobbers deployed content.
        if ($site->nginx_installed_at === null || $site->last_deploy_at === null) {
            $this->installPlaceholderPage($site, $ssh, $emit);
        }
        $this->ensureSuspendedPage($site, $ssh, $emit);
        $this->ensureManagedErrorPages($site, $ssh, $emit);
        $this->ensureNginxEngineHttpCacheInfrastructure($site, $ssh, $emit);
        $this->syncBasicAuthHtpasswdFiles($site, $ssh, $emit);
        $this->syncAccessGateFiles($site, $ssh, $emit);
        $this->writeNginxLayerSnippetFiles($site, $profile, $ssh, $emit);

        // Pool first: the vhost below points fastcgi_pass at this pool's socket,
        // so it must exist (and php-fpm reloaded) before nginx reloads onto it.
        $this->ensurePhpFpmPool($site, $ssh, $emit);

        // Read the on-box vhost once: the guard uses it to spot foreign edits,
        // and the TLS preflight uses it to salvage a still-valid certificate.
        $currentVhost = $this->readRemoteFile($server, $ssh, $confFile);

        // TLS preflight: never write a vhost that points ssl_certificate at a
        // file that isn't on the box. nginx -t hard-fails on a missing cert, and
        // because the apply symlinks before testing, that would leave the box
        // unable to reload at all. When the generated paths are missing but the
        // cert nginx is currently serving exists (e.g. a shared *.zone wildcard
        // a testing hostname rides), carry those working paths forward instead
        // of swapping a working cert for a per-host path certbot never created.
        $config = $this->reconcileTlsCertPaths($site, $server, $ssh, $config, $currentVhost, $emit);

        // Read-back guard: parse what's on the box and warn (or abort, per
        // config) before an overwrite silently discards manual vhost edits.
        $this->guardAgainstForeignOverwrite($server, $ssh, $confFile, $config, $emit, $currentVhost);

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
            // Atomic apply: a failed nginx -t must not leave the broken vhost
            // enabled, or every future reload on this box fails too. Restore the
            // previous vhost (or drop the file + symlink for a brand-new site)
            // and reload back onto the known-good config before surfacing the
            // error.
            $this->rollbackVhost($server, $ssh, $confFile, $linkFile, $currentVhost, $emit);

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
     * Read the current on-box vhost and, if overwriting it with $incoming would
     * delete directives a human added by hand, either warn the deploy console
     * (default) or abort the write (when DPLY_NGINX_OVERWRITE_GUARD=abort).
     *
     * Cheap and best-effort: a missing/empty current file or a parse failure is
     * treated as "nothing to protect" — `nginx -t` below remains the real syntax
     * gate, and this never blocks a deploy in the default warn mode.
     *
     * @param  Server  $server
     */
    protected function guardAgainstForeignOverwrite($server, SshConnection $ssh, string $confFile, string $incoming, ConsoleEmitter $emit, ?string $current = null): void
    {
        if ($this->guard->mode() === NginxConfigGuard::MODE_OFF) {
            return;
        }

        $current ??= $this->readRemoteFile($server, $ssh, $confFile);
        $foreign = $this->guard->foreignDirectives($current, $incoming);
        if ($foreign === []) {
            return;
        }

        $summary = $this->guard->summarize($foreign);

        Log::warning('nginx vhost overwrite would drop manual directives', [
            'conf_file' => $confFile,
            'foreign' => $foreign,
            'mode' => $this->guard->mode(),
        ]);

        if ($this->guard->mode() === NginxConfigGuard::MODE_ABORT) {
            throw new \RuntimeException($summary);
        }

        foreach (preg_split('/\r\n|\r|\n/', $summary) ?: [] as $line) {
            if (trim($line) !== '') {
                $emit->warn($line, 'nginx');
            }
        }
    }

    /**
     * Roll back a failed apply so the box is never left with a broken vhost
     * enabled (which would make every later reload on this box fail too).
     * Restores the previous file content, or removes the file + its sites-enabled
     * symlink when the site had no prior vhost, then best-effort reloads nginx
     * back onto the known-good config.
     */
    protected function rollbackVhost(Server $server, SshConnection $ssh, string $confFile, string $linkFile, ?string $previousVhost, ConsoleEmitter $emit): void
    {
        $emit->warn('nginx -t failed — rolling back to the previous working vhost', 'nginx');

        if ($previousVhost !== null) {
            $this->writeSystemFile($ssh, $confFile, $previousVhost);
        } else {
            $ssh->exec($this->privilegedCommand($server, sprintf(
                'rm -f %s %s',
                escapeshellarg($linkFile),
                escapeshellarg($confFile),
            )), 30);
        }

        // Re-sync on-disk state: reload onto the restored config. Best-effort —
        // the running nginx never picked up the bad config (the test failed), so
        // this just brings the file/symlink state back in line.
        $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_NGINX_RB_EXIT:%%s" "$?"',
            $this->privilegedCommand($server, NginxServiceScript::testAndReloadOrStartScript()),
        ), 60);
    }

    /**
     * If the generated vhost references ssl_certificate material that is not on
     * the box, but the live vhost points at cert files that ARE present, rewrite
     * the generated config to reuse those present paths. Prevents an overwrite
     * from swapping a working (often shared-wildcard) cert for a per-host path
     * certbot never created. Throws if no usable cert exists either way, leaving
     * the current vhost untouched rather than writing one nginx will reject.
     *
     * Pure-builder TLS path selection can't see the disk; this is the one place
     * with both the generated config and an SSH connection, so the on-disk truth
     * check lives here.
     */
    protected function reconcileTlsCertPaths(Site $site, Server $server, SshConnection $ssh, string $incoming, ?string $current, ConsoleEmitter $emit): string
    {
        $incomingPair = $this->extractCertPair($incoming);
        if ($incomingPair === null) {
            return $incoming; // no TLS block — nothing to reconcile
        }

        $present = $this->filesPresentOnBox($server, $ssh, [$incomingPair['cert'], $incomingPair['key']]);
        if (($present[$incomingPair['cert']] ?? false) && ($present[$incomingPair['key']] ?? false)) {
            return $incoming; // generated cert paths exist on disk — all good
        }

        // Salvage 1: the cert the live vhost is already serving (re-applies).
        $currentPair = $this->extractCertPair((string) $current);
        if ($currentPair !== null) {
            $curPresent = $this->filesPresentOnBox($server, $ssh, [$currentPair['cert'], $currentPair['key']]);
            if (($curPresent[$currentPair['cert']] ?? false) && ($curPresent[$currentPair['key']] ?? false)) {
                $emit->warn(sprintf(
                    'generated cert %s is not on the server; reusing the live cert %s',
                    $incomingPair['cert'],
                    $currentPair['cert'],
                ), 'nginx');

                return $this->swapCertPair($incoming, $currentPair);
            }
        }

        // Salvage 2: the covering per-server wildcard cert on disk (first apply
        // of a testing-hostname site, where there's no live vhost to copy from
        // but the shared *.zone cert is already installed).
        $wildcardPair = $this->coveringWildcardCertPair($site);
        if ($wildcardPair !== null) {
            $wcPresent = $this->filesPresentOnBox($server, $ssh, [$wildcardPair['cert'], $wildcardPair['key']]);
            if (($wcPresent[$wildcardPair['cert']] ?? false) && ($wcPresent[$wildcardPair['key']] ?? false)) {
                $emit->warn(sprintf(
                    'generated cert %s is not on the server; reusing the covering wildcard cert %s',
                    $incomingPair['cert'],
                    $wildcardPair['cert'],
                ), 'nginx');

                return $this->swapCertPair($incoming, $wildcardPair);
            }
        }

        throw new \RuntimeException(sprintf(
            'Refusing to write the vhost: ssl_certificate %s is not on the server and no valid certificate was found to substitute. The existing vhost was left untouched — install the certificate (or the covering wildcard), then re-apply.',
            $incomingPair['cert'],
        ));
    }

    /**
     * Extract the ssl_certificate / ssl_certificate_key paths from a vhost.
     * `ssl_certificate\s+` never matches the `ssl_certificate_key` line (no
     * whitespace after "ssl_certificate" there), so the two stay distinct.
     *
     * @return array{cert: string, key: string}|null
     */
    protected function extractCertPair(string $config): ?array
    {
        if (! preg_match('/^\s*ssl_certificate\s+(\S+?);/m', $config, $c)) {
            return null;
        }
        if (! preg_match('/^\s*ssl_certificate_key\s+(\S+?);/m', $config, $k)) {
            return null;
        }

        return ['cert' => $c[1], 'key' => $k[1]];
    }

    /**
     * Replace every ssl_certificate(_key) value in $config with the given pair.
     * Cert paths live under /etc/letsencrypt and contain no regex/replacement
     * metacharacters, so a literal preg_replace is safe.
     *
     * @param  array{cert: string, key: string}  $pair
     */
    protected function swapCertPair(string $config, array $pair): string
    {
        $config = (string) preg_replace('/^(\s*ssl_certificate)\s+\S+?;/m', '$1 '.$pair['cert'].';', $config);

        return (string) preg_replace('/^(\s*ssl_certificate_key)\s+\S+?;/m', '$1 '.$pair['key'].';', $config);
    }

    /**
     * Stat a set of paths on the box in one round trip.
     *
     * @param  list<string>  $paths
     * @return array<string, bool>
     */
    protected function filesPresentOnBox(Server $server, SshConnection $ssh, array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths)));
        if ($paths === []) {
            return [];
        }

        // The OK/NO marker must echo the RAW path, not the escapeshellarg'd
        // (single-quoted) form used for `test -f`. The presence regex below
        // matches `^NO <raw-path>$`; emitting `NO '<path>'` with literal quotes
        // never matches, so every path would fail open to "present" and silently
        // disable the TLS cert salvage (the missing cert then only surfaces at
        // nginx -t, which hard-fails and rolls the whole apply back).
        $checks = implode("\n", array_map(
            fn (string $p): string => sprintf('test -f %1$s && echo "OK %2$s" || echo "NO %2$s"', escapeshellarg($p), $p),
            $paths,
        ));
        $out = $ssh->exec($this->privilegedCommand($server, $checks), 30);

        // Fail open: a path is "missing" only when the box EXPLICITLY reports
        // `NO <path>`. Unrecognized output (a mocked/fake shell, a transport
        // hiccup) is treated as present so this preflight never aborts a deploy
        // on ambiguity — nginx -t and the rollback stay the real safety gates.
        $present = [];
        foreach ($paths as $p) {
            $present[$p] = ! (bool) preg_match('/^NO '.preg_quote($p, '/').'$/m', $out);
        }

        return $present;
    }

    /**
     * The on-disk cert/key pair for the per-server wildcard that covers this
     * site's testing hostname, or null when the site isn't wildcard-covered.
     * Mirrors {@see OpenLiteSpeedTlsPaths::letsEncryptDirectoryName}'s wildcard
     * branch: certbot stores the shared cert under /etc/letsencrypt/live/<zone>/.
     *
     * @return array{cert: string, key: string}|null
     */
    protected function coveringWildcardCertPair(Site $site): ?array
    {
        $wildcard = $site->coveringServerWildcard();
        if ($wildcard === null) {
            return null;
        }

        $dir = strtolower(trim((string) ($wildcard->live_directory ?: $site->testingZone())));
        if ($dir === '') {
            return null;
        }

        return [
            'cert' => '/etc/letsencrypt/live/'.$dir.'/fullchain.pem',
            'key' => '/etc/letsencrypt/live/'.$dir.'/privkey.pem',
        ];
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
     * Reads everything the editor needs (main vhost + before/after snippet files)
     * in a SINGLE SSH round trip instead of one connection + cat per file.
     *
     * The webserver-config page previously opened four separate connections on
     * load (read main, ensure layer files, read before, read after) — each a full
     * TCP+auth handshake. This collapses them into one `cat`-everything script
     * (base64-framed so arbitrary file content can't collide with the markers) on
     * one connection. It is a pure read: missing files come back as null and are
     * materialized on the next apply (the include globs tolerate their absence).
     *
     * @return array{main: ?string, before: ?string, after: ?string}
     */
    public function readEditorStateFromServer(Site $site): array
    {
        $server = $this->ensureServerReady($site);
        $ssh = $this->systemSsh($site);

        $basename = $this->configBasename($site);
        $confFile = rtrim(config('sites.nginx_sites_available'), '/').'/'.$basename.'.conf';
        $base = rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename;
        $beforeFile = $base.'/before/10-dply-layer.conf';
        $afterFile = $base.'/after/10-dply-layer.conf';

        // One script, one channel: emit each file base64-encoded on a single
        // marker line. `cat ... 2>/dev/null` swallows the shell's not-found error
        // so a missing file yields an empty (→ null) section without noise.
        $emit = fn (string $marker, string $path): string => sprintf(
            'printf %%s %s; cat %s 2>/dev/null | base64 | tr -d "\n"; printf "\n"',
            escapeshellarg($marker.':'),
            escapeshellarg($path),
        );
        $script = implode("\n", [
            $emit('DPLYMAIN', $confFile),
            $emit('DPLYBEFORE', $beforeFile),
            $emit('DPLYAFTER', $afterFile),
        ]);

        $out = $ssh->exec($this->privilegedCommand($server, $script), 60);

        return [
            'main' => $this->decodeEditorStateSection($out, 'DPLYMAIN'),
            'before' => $this->decodeEditorStateSection($out, 'DPLYBEFORE'),
            'after' => $this->decodeEditorStateSection($out, 'DPLYAFTER'),
        ];
    }

    /**
     * Pulls one base64 marker line out of {@see readEditorStateFromServer} output.
     * Mirrors {@see readRemoteFile} semantics: trimmed, empty becomes null.
     */
    private function decodeEditorStateSection(string $output, string $marker): ?string
    {
        if (! preg_match('/^'.preg_quote($marker, '/').':(.*)$/m', $output, $m)) {
            return null;
        }

        $decoded = base64_decode(trim($m[1]), true);
        if ($decoded === false) {
            return null;
        }

        $decoded = trim($decoded);

        return $decoded === '' ? null : $decoded;
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
        $beforeContent = ($beforeBody !== '' ? $beforeBody : SiteWebserverConfigProfile::DEFAULT_BEFORE_BODY)."\n";
        $afterContent = ($afterBody !== '' ? $afterBody : SiteWebserverConfigProfile::DEFAULT_AFTER_BODY)."\n";

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
