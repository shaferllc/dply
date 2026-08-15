<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;
use App\Support\Servers\ServerInstalledServices;

/**
 * Pushes /etc/dply/state.json to a server over SSH.
 *
 * The state file is the read-only contract between dply (the SaaS) and the
 * bash `dply` CLI on the box. The CLI never phones home — it reads sites,
 * recipes, log paths, and the managed-unit whitelist from this file.
 *
 * Dply calls this writer in two places:
 *   1. After installing the CLI on a server (initial bootstrap).
 *   2. (Phase 3 / future) After any config change that affects the payload
 *      — site added/removed, recipe edited, php version bumped.
 *
 * The payload is small (a few KB typical), so we rewrite it in full each
 * time rather than diffing. Schema version is baked in so the CLI can
 * detect a too-new file and degrade gracefully.
 */
class DplyCliStateWriter
{
    public const REMOTE_PATH = '/etc/dply/state.json';

    public const SCHEMA_VERSION = 1;

    /**
     * Build the state payload (no SSH I/O) — exposed so callers can inspect
     * what would be pushed without actually pushing.
     *
     * @return array<string, mixed>
     */
    public function build(Server $server): array
    {
        $phpVersion = ServerInstalledServices::phpVersionFor($server);
        $tags = ServerInstalledServices::tagsFor($server);

        $units = [];
        if (isset($tags['nginx'])) {
            $units[] = 'nginx';
        }
        if (isset($tags['apache'])) {
            $units[] = 'apache2';
        }
        if (isset($tags['caddy'])) {
            $units[] = 'caddy';
        }
        if (isset($tags['php'])) {
            $units[] = $phpVersion !== null ? "php{$phpVersion}-fpm" : 'php-fpm';
        }
        if (isset($tags['mysql'])) {
            $units[] = 'mysql';
        }
        if (isset($tags['postgres'])) {
            $units[] = 'postgresql';
        }
        if (isset($tags['redis'])) {
            $units[] = 'redis-server';
        }
        if (isset($tags['memcached'])) {
            $units[] = 'memcached';
        }
        if (isset($tags['supervisor'])) {
            $units[] = 'supervisor';
        }
        if (isset($tags['docker'])) {
            $units[] = 'docker';
        }

        $logPaths = ['syslog' => '/var/log/syslog', 'auth' => '/var/log/auth.log'];
        if (isset($tags['nginx'])) {
            $logPaths['nginx_error'] = '/var/log/nginx/error.log';
            $logPaths['nginx_access'] = '/var/log/nginx/access.log';
        }
        if (isset($tags['apache'])) {
            $logPaths['apache_error'] = '/var/log/apache2/error.log';
            $logPaths['apache_access'] = '/var/log/apache2/access.log';
        }
        if (isset($tags['php'])) {
            $logPaths['php_fpm'] = $phpVersion !== null
                ? "/var/log/php{$phpVersion}-fpm.log"
                : '/var/log/php-fpm.log';
        }
        if (isset($tags['mysql'])) {
            $logPaths['mysql_error'] = '/var/log/mysql/error.log';
        }
        if (isset($tags['postgres'])) {
            $logPaths['postgres'] = '/var/log/postgresql/';
        }
        if (isset($tags['redis'])) {
            $logPaths['redis'] = '/var/log/redis/redis-server.log';
        }

        $sites = $server->sites()->orderBy('name')->get(['id', 'name', 'slug', 'repository_path'])
            ->map(fn ($site) => [
                'id' => (string) $site->id,
                'name' => (string) $site->name,
                'slug' => (string) $site->slug,
                'path' => (string) ($site->repository_path ?: ''),
            ])
            ->all();

        $recipes = $server->recipes()->orderBy('name')->get(['id', 'name', 'script'])
            ->map(fn ($recipe) => [
                'id' => (string) $recipe->id,
                'name' => (string) $recipe->name,
                'script' => (string) $recipe->script,
            ])
            ->all();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'server' => [
                'id' => (string) $server->id,
                'name' => (string) $server->name,
                'ssh_user' => (string) ($server->ssh_user ?: 'root'),
            ],
            'services' => [
                'php_version' => $phpVersion,
                'php_fpm_unit' => isset($tags['php']) ? ($phpVersion !== null ? "php{$phpVersion}-fpm" : 'php-fpm') : null,
                'webserver_unit' => isset($tags['nginx']) ? 'nginx' : (isset($tags['apache']) ? 'apache2' : (isset($tags['caddy']) ? 'caddy' : null)),
                'db_unit' => isset($tags['mysql']) ? 'mysql' : (isset($tags['postgres']) ? 'postgresql' : null),
                'cache_unit' => isset($tags['redis']) ? 'redis-server' : (isset($tags['memcached']) ? 'memcached' : null),
                'units' => array_values($units),
                'log_paths' => $logPaths,
            ],
            'sites' => $sites,
            'recipes' => $recipes,
        ];
    }

    /**
     * Write the state file. Drops it at /etc/dply/state.json with
     * 0644 perms so the CLI can be invoked by any logged-in user.
     *
     * SFTP writes as the SSH user, who typically can't write directly under
     * /etc/dply (root-owned). Two-step: SFTP into /tmp first, then
     * `sudo install` into place with the right owner/mode.
     */
    public function push(Server $server, ?SshConnection $ssh = null): void
    {
        $payload = json_encode($this->build($server), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode dply state payload.');
        }

        $ssh ??= new SshConnection($server);

        $tmp = '/tmp/dply-state.'.bin2hex(random_bytes(8)).'.json';
        $ssh->putFile($tmp, $payload);
        $ssh->exec(
            'sudo mkdir -p '.escapeshellarg(dirname(self::REMOTE_PATH))
            .' && sudo install -o root -g root -m 0644 '.escapeshellarg($tmp).' '.escapeshellarg(self::REMOTE_PATH)
            .' && rm -f '.escapeshellarg($tmp),
            20,
        );
    }
}
