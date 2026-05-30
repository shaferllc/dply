<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Resolve Traefik's localhost API/dashboard base URL from static config.
 */
final class TraefikAdminUrl
{
    public const DEFAULT_ADDRESS = '127.0.0.1:9094';

    public const TRAEFIK_ENTRY_POINT = 'traefik';

    public static function fromAddress(?string $address): ?string
    {
        $address = trim((string) $address);
        if ($address === '') {
            return null;
        }

        if (str_starts_with($address, 'http://') || str_starts_with($address, 'https://')) {
            return rtrim($address, '/');
        }

        if (! str_starts_with($address, ':') && ! str_contains($address, ':')) {
            $address = '127.0.0.1'.$address;
        }

        if (str_starts_with($address, ':')) {
            $address = '127.0.0.1'.$address;
        }

        [$host, $port] = explode(':', $address, 2);
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
     * @param  array<string, mixed>  $parsed  Decoded traefik.yml
     */
    public static function fromStaticConfig(array $parsed): ?string
    {
        $entryPoints = $parsed['entryPoints'] ?? null;
        if (! is_array($entryPoints)) {
            return self::fromAddress(self::DEFAULT_ADDRESS);
        }

        $traefik = $entryPoints[self::TRAEFIK_ENTRY_POINT] ?? null;
        if (! is_array($traefik)) {
            return null;
        }

        $listen = $traefik['address'] ?? null;

        return self::fromAddress(is_string($listen) ? $listen : null);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    public static function apiDashboardEnabled(array $parsed): bool
    {
        $api = $parsed['api'] ?? null;
        if (! is_array($api)) {
            return self::hasTraefikEntryPoint($parsed);
        }

        if (! array_key_exists('dashboard', $api)) {
            return true;
        }

        return self::isTruthy($api['dashboard']);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    public static function apiInsecureEnabled(array $parsed): bool
    {
        $api = $parsed['api'] ?? null;
        if (! is_array($api)) {
            return self::hasTraefikEntryPoint($parsed);
        }

        if (! array_key_exists('insecure', $api)) {
            return self::hasTraefikEntryPoint($parsed);
        }

        return self::isTruthy($api['insecure']);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    public static function hasTraefikEntryPoint(array $parsed): bool
    {
        $entryPoints = $parsed['entryPoints'] ?? null;

        return is_array($entryPoints) && is_array($entryPoints[self::TRAEFIK_ENTRY_POINT] ?? null);
    }

    public static function isTruthy(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }
}
