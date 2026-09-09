<?php

declare(strict_types=1);

namespace App\Support\Providers;

use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Transport / outage failures against a cloud provider API — not auth.
 * Auth stays on {@see ProviderAuthFailure}; these mark the provider paused.
 */
final class ProviderCatalogFailure
{
    /** @var list<string> */
    private const PATTERNS = [
        'curl error 28',
        'curl error 7',
        'curl error 6',
        'operation timed out',
        'timed out',
        '0 bytes received',
        'could not resolve',
        'connection refused',
        'connection reset',
        'network is unreachable',
        'failed to connect',
        'unable to process request',
    ];

    public static function isUnreachable(Throwable|string|null $error): bool
    {
        if ($error instanceof ConnectionException) {
            return true;
        }

        $haystack = strtolower(trim($error instanceof Throwable ? $error->getMessage() : (string) $error));
        if ($haystack === '') {
            return false;
        }

        if (ProviderAuthFailure::detected($haystack)) {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function title(string $provider): string
    {
        return __(':provider is unavailable', [
            'provider' => ProviderAuthFailure::providerLabel($provider),
        ]);
    }

    public static function message(string $provider): string
    {
        return __(':provider’s API isn’t reachable right now. Creating new servers on this provider is paused until it responds.', [
            'provider' => ProviderAuthFailure::providerLabel($provider),
        ]);
    }

    public static function sanitize(?string $message, string $provider): string
    {
        $raw = trim((string) $message);
        if ($raw === '' || self::isUnreachable($raw) || self::leaksTransportInternals($raw)) {
            return self::message($provider);
        }

        if (ProviderAuthFailure::detected($raw)) {
            return ProviderAuthFailure::message($provider);
        }

        return $raw;
    }

    private static function leaksTransportInternals(string $message): bool
    {
        $haystack = strtolower($message);

        return str_contains($haystack, 'curl error')
            || str_contains($haystack, 'curl.se')
            || str_contains($haystack, 'api.digitalocean.com')
            || str_contains($haystack, 'api.hetzner.cloud')
            || str_contains($haystack, 'api.vultr.com')
            || str_contains($haystack, 'api.linode.com');
    }
}
