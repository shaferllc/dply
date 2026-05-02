<?php

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerProvisionArtifact;

/**
 * Detect which managed services were actually installed on a server. Drives UI gating
 * (Logs sources dropdown, Insights catalog) so we don't surface knobs for services that
 * aren't present.
 *
 * Source of truth is the `stack_summary` artifact written by ServerProvisionCommandBuilder
 * (latest provision run). Tags returned are normalized strings: 'nginx', 'apache',
 * 'openlitespeed', 'traefik', 'caddy', 'php', 'mysql', 'postgres', 'redis', 'valkey',
 * 'memcached', 'supervisor', 'docker', 'haproxy'.
 */
class ServerInstalledServices
{
    /**
     * Always-on system services that don't depend on the chosen stack — auth.log, syslog,
     * UFW, SSL/letsencrypt logs, and Dply's own activity log are present on every Ubuntu
     * box we provision.
     *
     * @var list<string>
     */
    public const ALWAYS_PRESENT = ['ufw', 'auth', 'syslog', 'letsencrypt', 'dply'];

    /**
     * @return array<string, true> Set of installed service tags (use array_key_exists for fast lookup).
     */
    public static function tagsFor(Server $server): array
    {
        $stack = self::stackSummary($server);
        $tags = [];

        foreach (self::ALWAYS_PRESENT as $tag) {
            $tags[$tag] = true;
        }

        if ($stack === null) {
            // No provision artifact yet (server still building, or pre-existing). Fail open
            // so we don't blank out the UI — the caller can still surface everything.
            return $tags + ['unknown' => true];
        }

        $expected = is_array($stack['expected_services'] ?? null) ? $stack['expected_services'] : [];
        foreach ($expected as $svc) {
            $svc = strtolower((string) $svc);
            $tags[match ($svc) {
                'php-fpm' => 'php',
                'docker-daemon' => 'docker',
                'caddy-backend' => 'caddy',
                'postgresql' => 'postgres',
                default => $svc,
            }] = true;
        }

        // Fall back to the high-level fields too, so older servers without the granular
        // expected_services entry still resolve.
        $web = strtolower((string) ($stack['webserver'] ?? ''));
        if ($web !== '' && $web !== 'none') {
            $tags[$web] = true;
        }
        $db = strtolower((string) ($stack['database'] ?? ''));
        if (str_starts_with($db, 'postgres')) {
            $tags['postgres'] = true;
        } elseif ($db !== '' && $db !== 'none' && $db !== 'sqlite3') {
            // mysql / mariadb both surface as 'mysql' — they share log paths and tools.
            $tags['mysql'] = true;
        }
        $cache = strtolower((string) ($stack['cache_service'] ?? ''));
        if ($cache === 'redis' || $cache === 'valkey') {
            $tags['redis'] = true; // valkey is wire-compatible with redis logs/CLI
        } elseif ($cache === 'memcached') {
            $tags['memcached'] = true;
        }
        $php = (string) ($stack['php_version'] ?? '');
        if ($php !== '' && $php !== 'none') {
            $tags['php'] = true;
        }

        if ($server->supervisor_package_status === Server::SUPERVISOR_PACKAGE_INSTALLED) {
            $tags['supervisor'] = true;
        }

        return $tags;
    }

    public static function has(Server $server, string $tag): bool
    {
        return array_key_exists($tag, self::tagsFor($server));
    }

    /**
     * @param  list<string>  $tags
     */
    public static function hasAny(Server $server, array $tags): bool
    {
        $installed = self::tagsFor($server);
        foreach ($tags as $tag) {
            if (array_key_exists($tag, $installed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function stackSummary(Server $server): ?array
    {
        $artifact = ServerProvisionArtifact::query()
            ->whereHas('run', fn ($q) => $q->where('server_id', $server->id))
            ->where('type', 'stack_summary')
            ->latest('id')
            ->first();

        if (! $artifact instanceof ServerProvisionArtifact) {
            return null;
        }

        $meta = $artifact->metadata;
        if (is_array($meta) && $meta !== []) {
            return $meta;
        }

        $decoded = json_decode((string) $artifact->content, true);

        return is_array($decoded) ? $decoded : null;
    }
}
