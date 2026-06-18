<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Jobs\ValidateSiteBindingsReachableJob;
use App\Models\SiteBinding;

/**
 * Resolves the reachable host:port for a site resource binding — the endpoint
 * the site's server must be able to open a TCP socket to at runtime. Used by
 * {@see ValidateSiteBindingsReachableJob} to probe every networked
 * binding from the box, and by the Resources map to decide which nodes can show
 * a reachability badge.
 *
 * Only "networked" types carry a dialable endpoint. Driver-only bindings
 * (cache/queue/session) ride the redis/database connection they reference, so
 * their reachability is that binding's; marker types (scheduler/workers/
 * publication) have nothing to reach.
 */
final class BindingReachability
{
    /** Types we attempt a TCP reachability probe for. */
    public const NETWORKED = ['database', 'redis', 'storage', 'mail', 'broadcasting', 'logging'];

    public static function isNetworked(string $type): bool
    {
        return in_array($type, self::NETWORKED, true);
    }

    /**
     * The host:port to probe for this binding, or null when there's nothing
     * dialable (non-networked type, or a networked binding with no endpoint —
     * e.g. the "log" mailer or dply Realtime's internal drain).
     *
     * @return array{host: string, port: int}|null
     */
    public static function target(SiteBinding $binding): ?array
    {
        if (! self::isNetworked($binding->type)) {
            return null;
        }

        $env = $binding->connectionEnv();
        $cfg = ($binding->config );
        $provider = strtolower(trim((string) ($cfg['provider'] ?? '')));

        return match ($binding->type) {
            'database' => self::clean($env['DB_HOST'] ?? '', $env['DB_PORT'] ?? null, self::dbPort((string) ($env['DB_CONNECTION'] ?? ''))),
            'redis' => self::clean($env['REDIS_HOST'] ?? '', $env['REDIS_PORT'] ?? null, 6379),
            'broadcasting' => self::clean($env['PUSHER_HOST'] ?? '', $env['PUSHER_PORT'] ?? null, 443),
            'storage' => self::storage($env),
            'mail' => self::mail($env, $provider),
            'logging' => self::logging($env, $cfg),
            default => null,
        };
    }

    private static function dbPort(string $conn): int
    {
        return match (strtolower($conn)) {
            'pgsql', 'postgres', 'postgresql' => 5432,
            'sqlsrv' => 1433,
            default => 3306,
        };
    }

    /**
     * @param  array<string, mixed> $env
     * @return array{host: string, port: int}|null
     */
    private static function storage(array $env): ?array
    {
        $endpoint = trim((string) ($env['AWS_ENDPOINT'] ?? $env['AWS_URL'] ?? ''));
        if ($endpoint !== '') {
            $host = (string) (parse_url($endpoint, PHP_URL_HOST) ?: '');
            $scheme = strtolower((string) (parse_url($endpoint, PHP_URL_SCHEME) ?: 'https'));
            $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?: ($scheme === 'http' ? 80 : 443));

            return self::clean($host, $port, $port);
        }

        // No custom endpoint => real AWS S3 at the regional host.
        $region = trim((string) ($env['AWS_DEFAULT_REGION'] ?? ''));

        return $region !== '' ? self::clean('s3.'.$region.'.amazonaws.com', 443, 443) : null;
    }

    /**
     * @param  array<string, mixed> $env
     * @return array{host: string, port: int}|null
     */
    private static function mail(array $env, string $provider): ?array
    {
        // SMTP transports carry an explicit host:port.
        $host = trim((string) ($env['MAIL_HOST'] ?? ''));
        if ($host !== '') {
            return self::clean($host, $env['MAIL_PORT'] ?? null, 587);
        }

        // API transports talk to a fixed HTTPS endpoint; "log" reaches nothing.
        $region = trim((string) ($env['AWS_DEFAULT_REGION'] ?? 'us-east-1'));
        $apiHost = match ($provider) {
            'mailgun' => 'api.mailgun.net',
            'postmark' => 'api.postmarkapp.com',
            'resend' => 'api.resend.com',
            'ses' => 'email.'.$region.'.amazonaws.com',
            default => '',
        };

        return $apiHost !== '' ? self::clean($apiHost, 443, 443) : null;
    }

    /**
     * @param  array<string, mixed> $env
     * @param  array<string, mixed> $cfg
     * @return array{host: string, port: int}|null
     */
    private static function logging(array $env, array $cfg): ?array
    {
        // Drain credentials usually stamp an explicit host/port into config.
        $host = trim((string) ($cfg['host'] ?? ''));
        if ($host !== '') {
            return self::clean($host, $cfg['port'] ?? null, 443);
        }

        // Otherwise look for a *_HOST (+ sibling *_PORT) in the injected env.
        return self::scan($env);
    }

    /**
     * Find the first `<PREFIX>_HOST` env key with a matching `<PREFIX>_PORT`.
     *
     * @param  array<string, mixed> $env
     * @return array{host: string, port: int}|null
     */
    private static function scan(array $env): ?array
    {
        foreach ($env as $k => $v) {
            if (preg_match('/^(.*?)_?HOST$/i', (string) $k, $m) !== 1) {
                continue;
            }
            $prefix = $m[1];
            foreach ($env as $pk => $pv) {
                if (preg_match('/^'.preg_quote($prefix, '/').'_?PORT$/i', (string) $pk) === 1) {
                    return self::clean((string) $v, $pv, 0);
                }
            }

            return self::clean((string) $v, null, 443);
        }

        return null;
    }

    /**
     * Sanitize a host (hostnames/IPs only — this gets shell-interpolated) and a
     * port, falling back to $default when the port is missing/invalid.
     *
     * @return array{host: string, port: int}|null
     */
    private static function clean(string $host, mixed $port, int $default): ?array
    {
        $host = trim($host);
        if ($host === '' || preg_match('/^[A-Za-z0-9_.:-]+$/', $host) !== 1) {
            return null;
        }

        $port = (int) ($port ?: 0);
        if ($port < 1 || $port > 65535) {
            $port = $default;
        }
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return ['host' => $host, 'port' => $port];
    }
}
