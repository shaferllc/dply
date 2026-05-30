<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Resolve Caddy's admin API listen address to an HTTP base URL for curl.
 */
final class CaddyAdminUrl
{
    public static function fromListenDirective(?string $listen): ?string
    {
        $listen = strtolower(trim((string) $listen));
        if ($listen === '' || $listen === 'off') {
            return null;
        }

        if (str_starts_with($listen, 'http://') || str_starts_with($listen, 'https://')) {
            return rtrim($listen, '/');
        }

        if (! str_contains($listen, ':')) {
            $listen .= ':2019';
        }

        [$host, $port] = explode(':', $listen, 2);
        $host = trim($host);
        $port = trim($port);

        if ($host === '' || $port === '' || ! ctype_digit($port)) {
            return null;
        }

        if ($host === 'localhost' || $host === '::1') {
            $host = '127.0.0.1';
        }

        return 'http://'.$host.':'.$port;
    }

    /**
     * @param  array<string, mixed>  $config  Decoded GET /config/ JSON
     */
    public static function fromLoadedConfig(array $config): ?string
    {
        $admin = $config['admin'] ?? null;
        if (! is_array($admin)) {
            return self::fromListenDirective('localhost:2019');
        }

        $listen = $admin['listen'] ?? $admin['address'] ?? null;

        return self::fromListenDirective(is_string($listen) ? $listen : null);
    }
}
