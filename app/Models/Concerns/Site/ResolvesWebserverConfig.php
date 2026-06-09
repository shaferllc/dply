<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Enums\SiteType;
use App\Jobs\RelocateSiteFilesJob;
use App\Jobs\ScanSiteEnvRequirementsJob;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;

/**
 * Extracted from {@see \App\Models\Site}. Composed back into the model via `use`.
 */
trait ResolvesWebserverConfig
{
    public function webserver(): string
    {
        if ($this->usesFunctionsRuntime()) {
            return 'digitalocean_functions';
        }

        if ($this->usesDockerRuntime()) {
            return 'docker';
        }

        if ($this->usesKubernetesRuntime()) {
            return 'kubernetes';
        }

        $serverMeta = is_array($this->server?->meta) ? $this->server->meta : [];
        $webserver = $serverMeta['webserver'] ?? 'nginx';

        return is_string($webserver) && $webserver !== '' ? $webserver : 'nginx';
    }

    public function supportsWebserver(): bool
    {
        return $this->type instanceof SiteType
            ? $this->type->managesWebserver()
            : true;
    }

    public function effectiveRepositoryPath(): string
    {
        $path = $this->repository_path;
        if ($path !== null && $path !== '') {
            return $path;
        }

        // Container sites have neither a repo path nor a document
        // root — return a stable placeholder so callers that derive
        // sub-paths (basic-auth dir, etc.) can still build strings.
        return $this->document_root ?? $this->conventionalRepositoryPath();
    }

    /**
     * The canonical on-disk location for a site's files: /home/dply/<domain>,
     * keyed on the primary hostname (DNS-safe chars only), falling back to the
     * slug when no domain is set. This is the convention new sites default to
     * and the target {@see RelocateSiteFilesJob} relocates toward.
     */
    public function conventionalRepositoryPath(): string
    {
        // Strip disallowed chars (repo dirs have no separator), fall back to slug.
        $host = $this->primaryHostSlug('');

        if ($host === '') {
            $host = $this->slug ?: 'site';
        }

        return '/home/dply/'.$host;
    }

    /**
     * Normalize the primary hostname into a filesystem-safe stem shared by the
     * repository path and the vhost basename: lowercase, drop anything outside
     * [a-z0-9.-], and trim stray dots/dashes. $separator is what disallowed runs
     * collapse to — the repo path strips them ('') while the vhost basename
     * dashes them ('-'); it stays a parameter so each caller's on-disk output is
     * unchanged. Empty when the site has no usable hostname (callers fall back).
     */
    private function primaryHostSlug(string $separator): string
    {
        $host = strtolower(trim((string) optional($this->primaryDomain())->hostname));
        $host = (string) preg_replace('/[^a-z0-9.-]+/', $separator, $host);

        return trim($host, '.-');
    }

    /**
     * Linux account for this site's files / PHP-FPM: explicit {@see $php_fpm_user} or the server's deploy SSH user.
     */
    public function effectiveSystemUser(Server $server): string
    {
        $explicit = trim((string) ($this->php_fpm_user ?? ''));

        if ($explicit !== '') {
            return $explicit;
        }

        return trim((string) ($server->ssh_user ?? ''));
    }

    /**
     * Every PHP site runs in its own PHP-FPM pool (Forge-style isolation), so
     * request_terminate_timeout, pm.* and the run user can be tuned per site
     * without affecting neighbours. Non-PHP sites (static/node/octane) never
     * touch a pool. Octane sites proxy to a port, not a FastCGI socket, so they
     * opt out too.
     *
     * Scoped to nginx + Caddy — the two engines whose provisioners create the
     * pool. Apache/OpenLiteSpeed keep the shared socket (OLS uses lsphp, not
     * php-fpm), so we must NOT point their vhosts at a pool that nothing writes.
     */
    public function usesDedicatedPhpFpmPool(): bool
    {
        return $this->type === SiteType::Php
            && empty($this->octane_port)
            && in_array($this->webserver(), ['nginx', 'caddy'], true);
    }

    /**
     * The pool name == the on-disk pool conf basename == the vhost basename, so
     * the three always agree and an operator can grep one to find the others.
     */
    public function phpFpmPoolName(): string
    {
        return $this->webserverConfigBasename();
    }

    /**
     * The unix socket this site's dedicated pool listens on. Deliberately
     * version-FREE: the socket name never changes when the PHP version does, so
     * a version switch is a pool-conf move (handled by the provisioner) and the
     * vhost never has to be rewritten for it. Directory tracks the configured
     * shared-socket location so DPLY_PHP_FPM_SOCKET overrides are honoured.
     */
    public function phpFpmListenSocketPath(): string
    {
        $dir = rtrim(dirname((string) config('sites.php_fpm_socket', '/run/php/php{version}-fpm.sock')), '/');

        return ($dir !== '' ? $dir : '/run/php').'/'.$this->phpFpmPoolName().'.sock';
    }

    /**
     * The PHP-FPM version whose `pool.d` dir this site's pool conf lives in (and
     * whose fpm master serves it). Mirrors the socket-version guard in the nginx
     * builder: trust the site's configured version only when it's actually
     * installed, else the server's provisioned primary, else a safe default.
     */
    public function resolvedPhpFpmVersion(): string
    {
        $server = $this->server;
        $installedPrimary = $server !== null
            ? \App\Support\Servers\InstalledStack::fromMeta($server)->phpVersion
            : null;
        $configured = $this->phpVersion();

        if ($configured !== null && $configured !== '') {
            $installed = [];
            if ($server !== null) {
                foreach ((array) data_get($server->meta, 'php_inventory.installed_versions', []) as $v) {
                    $id = (string) (is_array($v) ? ($v['version'] ?? $v['id'] ?? '') : $v);
                    if ($id !== '') {
                        $installed[] = $id;
                    }
                }
            }
            if ($installedPrimary !== null && $installedPrimary !== '' && ! in_array($installedPrimary, $installed, true)) {
                $installed[] = $installedPrimary;
            }
            if ($installed === [] || in_array($configured, $installed, true)) {
                return $configured;
            }
        }

        return ($installedPrimary !== null && $installedPrimary !== '') ? $installedPrimary : '8.3';
    }

    /**
     * Per-site PHP-FPM pool process settings, merged over sane defaults. Stored
     * in meta['php_fpm_pool']; the start/spare-server counts are DERIVED from
     * max_children by {@see \App\Services\Sites\SitePhpFpmPoolConfigBuilder}.
     *
     * @return array{pm: string, max_children: int, max_requests: int, request_terminate_timeout: int}
     */
    public function phpFpmPoolSettings(): array
    {
        $meta = is_array($this->meta['php_fpm_pool'] ?? null) ? $this->meta['php_fpm_pool'] : [];

        $pm = is_string($meta['pm'] ?? null) ? $meta['pm'] : 'dynamic';
        if (! in_array($pm, ['dynamic', 'static', 'ondemand'], true)) {
            $pm = 'dynamic';
        }

        $int = static fn (mixed $v, int $default, int $min): int => is_numeric($v) && (int) $v >= $min ? (int) $v : $default;

        return [
            'pm' => $pm,
            'max_children' => $int($meta['max_children'] ?? null, 10, 1),
            'max_requests' => $int($meta['max_requests'] ?? null, 500, 0),
            'request_terminate_timeout' => $int($meta['request_terminate_timeout'] ?? null, 120, 1),
        ];
    }

    /**
     * Web root for Nginx (atomic → …/current/public).
     */
    public function effectiveDocumentRootForNginx(): string
    {
        if ($this->isAtomicDeploys()) {
            return rtrim($this->effectiveRepositoryPath(), '/').'/current/public';
        }

        return rtrim($this->document_root, '/');
    }

    public function effectiveDocumentRoot(): string
    {
        return $this->effectiveDocumentRootForNginx();
    }

    /**
     * Directory that receives .env — always the site's PROJECT ROOT, never the
     * public docroot, so the file sits one level above the web-served directory.
     */
    public function effectiveEnvDirectory(): string
    {
        $projectRoot = $this->effectiveProjectRoot();

        if ($this->isAtomicDeploys()) {
            return $projectRoot.'/current';
        }

        return $projectRoot;
    }

    /**
     * The site's project root (clone target) for env / path derivation. Prefers
     * the explicit repository_path; otherwise the conventional /home/dply/<host>
     * — deliberately NOT document_root, which points at the …/public subdir.
     * Using document_root here would drop the .env *inside* the served directory,
     * leaving only the webserver deny-rule between it and the public internet.
     */
    public function effectiveProjectRoot(): string
    {
        $path = $this->repository_path;
        if ($path !== null && $path !== '') {
            return rtrim($path, '/');
        }

        return rtrim($this->conventionalRepositoryPath(), '/');
    }

    /**
     * Absolute path on the host where Dply reads/writes the .env file.
     * Defaults to {@see effectiveEnvDirectory()}/.env, but the operator can
     * override via the env_file_path column to relocate the file outside the
     * docroot — e.g. /etc/dply/<slug>.env — so it cannot be served by the
     * webserver even if the deny rule is bypassed.
     *
     * Override paths are validated to be absolute at the service layer; this
     * helper trusts the stored value (validation lives at write time, not
     * read time).
     */
    public function effectiveEnvFilePath(): string
    {
        $override = trim((string) ($this->env_file_path ?? ''));
        if ($override !== '') {
            return $override;
        }

        return rtrim($this->effectiveEnvDirectory(), '/').'/.env';
    }

    /**
     * The detected env-var requirements cached by {@see ScanSiteEnvRequirementsJob}
     * (from .env.example + env() usages in code and config/). Empty until the
     * first scan runs.
     *
     * @return array{scanned_at?: string, root?: string, example_path?: ?string, keys?: list<array<string, mixed>>}
     */
    public function envRequirements(): array
    {
        $req = $this->meta['env_requirements'] ?? null;

        return is_array($req) ? $req : [];
    }

    /**
     * Required env keys the site declares but that aren't satisfied — i.e. not
     * present with a non-empty value in its .env cache and not inherited from
     * the workspace. Drives the "missing variables" warning and the deploy
     * gate.
     *
     * "Required" is the INTERSECTION of two signals: the key is referenced by
     * env() with no default argument (`code` — the app genuinely can't fall
     * back) AND the author declared it in .env.example (`example` — they
     * intend every deploy to set it). Requiring both keeps the list realistic:
     *   - .env.example keys that carry a config default (APP_DEBUG, APP_ENV,
     *     locales, BCRYPT_ROUNDS) are optional → excluded (no `code`).
     *   - no-default refs to optional integrations the author did NOT put in
     *     .env.example (ABLY_KEY, BITBUCKET_*, CLOUDFLARE_*, AWS_EC2_*) are
     *     opt-in → excluded (no `example`).
     * On the dply monorepo this collapses ~990 detected vars to ~13 genuinely
     * required ones (APP_KEY, AWS creds, MAIL_*, REDIS_PASSWORD, REVERB_*).
     *
     * @param  list<string>  $presentKeys  keys already set with a non-empty value
     * @param  list<string>  $inheritedKeys  workspace-inherited keys (also count as satisfied)
     * @return list<array{key: string, sources: list<string>, required: bool, example: ?string}>
     */
    public function missingRequiredEnvKeys(array $presentKeys, array $inheritedKeys = []): array
    {
        // Per-variable opt-outs (operator ignored specific keys) count as
        // satisfied — like skip_env_gate but granular.
        $ignored = array_values((array) ($this->meta['ignored_env_keys'] ?? []));
        $satisfied = array_flip([...$presentKeys, ...$inheritedKeys, ...$ignored]);

        $missing = [];
        foreach (($this->envRequirements()['keys'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $sources = array_values((array) ($entry['sources'] ?? []));
            // Both signals required (see method doc): no-default usage AND
            // declared in .env.example.
            if (! in_array('code', $sources, true) || ! in_array('example', $sources, true)) {
                continue;
            }
            $key = (string) ($entry['key'] ?? '');
            if ($key === '' || isset($satisfied[$key])) {
                continue;
            }
            $missing[] = [
                'key' => $key,
                'sources' => $sources,
                'required' => true,
                'example' => isset($entry['example']) ? (string) $entry['example'] : null,
            ];
        }

        // Vars declared required in the repo dply.yaml `env:` section are also
        // requirements even when the scanner didn't flag them — the repo author
        // explicitly declared them. Manifest is authoritative for env shape.
        $listed = array_flip(array_map(static fn (array $m): string => $m['key'], $missing));
        foreach ($this->manifestEnvDeclarations() as $decl) {
            $key = (string) ($decl['name'] ?? '');
            if ($key === '' || ($decl['required'] ?? true) !== true) {
                continue;
            }
            if (isset($satisfied[$key]) || isset($listed[$key])) {
                continue;
            }
            $missing[] = [
                'key' => $key,
                'sources' => ['manifest'],
                'required' => true,
                'example' => isset($decl['default']) ? (string) $decl['default'] : null,
            ];
            $listed[$key] = true;
        }

        return $missing;
    }

    /**
     * Env-var declarations from the repo dply.yaml `env:` section, synced after
     * each BYO deploy (see ByoRepoConfigSync). Drives both the deploy gate
     * (required keys) and env-editor prefill (names + non-secret defaults).
     *
     * @return list<array{name: string, required: bool, description: ?string, default: ?string}>
     */
    public function manifestEnvDeclarations(): array
    {
        $decls = data_get($this->meta, 'byo.repo_config.snapshot.env_declarations');

        return is_array($decls) ? array_values(array_filter($decls, 'is_array')) : [];
    }

    /**
     * Managed VM vhosts may emit engine-level HTTP cache directives (e.g. nginx FastCGI / proxy_cache).
     *
     * Reads from {@see Site::cachingConfig()} (`meta['caching']`) and falls back to the legacy
     * boolean column for sites that haven't run through the `migrate_engine_http_cache_to_meta_caching`
     * migration yet. The boolean column is also kept in sync by a `saving` observer so existing
     * direct-column reads keep working until the column is dropped in a follow-up release.
     */
    public function wantsEngineHttpCache(): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        if ($this->usesFunctionsRuntime() || $this->usesDockerRuntime() || $this->usesKubernetesRuntime()) {
            return false;
        }

        return $this->hasCachingMethod('nginx_http');
    }

    /**
     * @return array<string, mixed>
     */
    public function cdnConfig(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $cdn = $meta['cdn'] ?? null;

        return is_array($cdn) ? $cdn : [];
    }

    public function cachingConfig(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $caching = $meta['caching'] ?? null;

        if (is_array($caching)) {
            return $caching;
        }

        $legacyEnabled = (bool) $this->engine_http_cache_enabled;

        return [
            'enabled' => $legacyEnabled,
            'methods' => $legacyEnabled ? ['nginx_http'] : [],
            'nginx_http' => [
                'fcgi' => ['ttl_200' => '60m', 'ttl_404' => '10m', 'min_uses' => 1],
                'proxy' => ['ttl_200' => '60m', 'ttl_404' => '10m'],
                'bypass_cookies' => [],
            ],
            'lscache' => ['enabled' => false, 'rules' => []],
            'varnish' => ['enabled' => false, 'ttl_default' => '120s'],
        ];
    }

    /**
     * Whether the master caching toggle is on AND the given method id appears in `methods`.
     * The single gate every consumer should funnel through — keeps the "enabled vs methods"
     * invariant in one place.
     */
    public function hasCachingMethod(string $method): bool
    {
        $cfg = $this->cachingConfig();
        if (empty($cfg['enabled'])) {
            return false;
        }
        $methods = $cfg['methods'] ?? [];

        return is_array($methods) && in_array($method, $methods, true);
    }

    /**
     * Methods this site is eligible to enable, given its type/runtime/webserver. Single source
     * of truth for the Livewire toggle list, validation, and the audit-event payload.
     *
     * Webserver-native cache modules surface only for the webserver the server currently runs;
     * Varnish + OPcache are webserver-agnostic and surface for any non-container PHP/static/node
     * site. v2 will add `apache_modcache` and `caddy_souin`.
     *
     * @return list<string>
     */
    public function availableCachingMethods(): array
    {
        if ($this->usesFunctionsRuntime() || $this->usesDockerRuntime() || $this->usesKubernetesRuntime()) {
            return [];
        }

        $serverMeta = is_array($this->server?->meta) ? $this->server->meta : [];
        $webserver = strtolower((string) ($serverMeta['webserver'] ?? 'nginx'));

        $methods = ['varnish'];

        if ($this->type === SiteType::Php) {
            $methods[] = 'opcache';
        }

        switch ($webserver) {
            case 'nginx':
                $methods[] = 'nginx_http';
                break;
            case 'openlitespeed':
                $methods[] = 'lscache';
                break;
                // apache mod_cache + caddy souin land in v2.
        }

        return array_values(array_unique($methods));
    }

    /**
     * Static web root for the suspended HTML page (outside public/).
     */
    public function suspendedStaticRoot(): string
    {
        return rtrim($this->effectiveEnvDirectory(), '/').'/.dply/suspended';
    }

    /**
     * Static web root for the worker-host "no web interface" page (outside
     * public/). Served by Caddy for every request on a worker site so the
     * deployed code is never browsable. See {@see Site::isWorkerSite()}.
     */
    public function workerStaticRoot(): string
    {
        return rtrim($this->effectiveEnvDirectory(), '/').'/.dply/worker';
    }

    /**
     * Static files for managed 5xx error pages (outside public/).
     */
    public function managedErrorPagesRoot(): string
    {
        return rtrim($this->effectiveEnvDirectory(), '/').'/.dply/errors';
    }

    /**
     * Optional text shown on the public suspended HTML page (escaped when rendered).
     * Prefers {@see Site::$meta} `suspended_message`, then legacy {@see Site::$suspended_reason}.
     */
    public function suspendedPublicMessage(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $fromMeta = trim((string) ($meta['suspended_message'] ?? ''));

        if ($fromMeta !== '') {
            return $fromMeta;
        }

        return trim((string) ($this->suspended_reason ?? ''));
    }

    /**
     * Legacy vhost basename — `dply-<id>-<slug>`. Kept as the fallback for any
     * site that predates {@see assignWebserverConfigBasename()} and therefore has
     * no frozen basename in meta, so we still resolve their on-disk config files.
     */
    public function nginxConfigBasename(): string
    {
        return 'dply-'.$this->id.'-'.$this->slug;
    }

    /**
     * The on-disk vhost basename for this site. Once {@see assignWebserverConfigBasename()}
     * has frozen a domain-based name into meta we always return that (it must stay
     * stable even if the primary domain later changes, or we'd orphan the file on
     * disk); sites without a frozen name fall back to the legacy `dply-<id>-<slug>`.
     */
    public function webserverConfigBasename(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $stored = trim((string) ($meta['webserver_config_basename'] ?? ''));

        return $stored !== '' ? $stored : $this->nginxConfigBasename();
    }

    /**
     * Compute a human-findable vhost basename keyed on the primary domain with
     * the `dply-` grouping prefix and the site id appended, e.g.
     * `dply-example.com-01kt81w92gdc72gs4yznz5ndpc`. An operator can find a site's
     * config with `ls sites-available | grep example.com`, while the id keeps it
     * unique and traceable back to the record. Falls back to the slug when the
     * site has no usable hostname yet.
     */
    public function freshWebserverConfigBasename(): string
    {
        // Dash disallowed chars (keeps the hostname readable in the filename),
        // fall back to slug when there's no usable hostname yet.
        $host = $this->primaryHostSlug('-');
        $base = $host !== '' ? $host : (string) $this->slug;

        return 'dply-'.$base.'-'.$this->id;
    }

    /**
     * Freeze a human-findable vhost basename into meta the first time a site is
     * provisioned, so the on-disk filename stays stable even if the primary domain
     * later changes. Idempotent: returns the already-frozen name when present, so
     * re-provisioning never re-points a site at a new file (which would orphan the
     * old vhost on disk). Existing sites that never had one frozen keep the legacy
     * `dply-<id>-<slug>` name via {@see webserverConfigBasename()}.
     */
    public function assignWebserverConfigBasename(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $existing = trim((string) ($meta['webserver_config_basename'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $basename = $this->freshWebserverConfigBasename();
        $meta['webserver_config_basename'] = $basename;
        $this->forceFill(['meta' => $meta])->save();

        return $basename;
    }

    public function webserverLogDirectory(): string
    {
        return match ($this->webserver()) {
            'apache' => '/var/log/apache2',
            'caddy' => '/var/log/caddy',
            'openlitespeed' => '/var/log/lshttpd',
            'traefik' => '/var/log/caddy',
            default => '/var/log/nginx',
        };
    }
}
