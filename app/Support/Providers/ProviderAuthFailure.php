<?php

declare(strict_types=1);

namespace App\Support\Providers;

use App\Enums\ServerProvider;

/**
 * Detects a rejected / expired provider API token from the message a backend
 * threw. Used so every IaaS (DigitalOcean, Hetzner, Vultr, …) can force
 * reconnect instead of showing a raw "Unable to authenticate you" dump.
 */
final class ProviderAuthFailure
{
    /** @var list<string> */
    private const PATTERNS = [
        'unable to authenticate',
        'authenticate you',
        'unauthenticated',
        'unauthorized',
        'invalid access token',
        'invalid api token',
        'invalid api key',
        'invalid token',
        'token is invalid',
        'token expired',
        'expired token',
        'authentication failed',
        'invalid authentication',
        'not authenticated',
        'authorization failed',
        'invalid_client',
        'invalidclienttokenid',
        'authfailure',
        'unrecognized token',
        'bad credentials',
        'api token rejected',
        'rejected this api token',
    ];

    public static function detected(?string $message, ?int $status = null): bool
    {
        if ($status === 401) {
            return true;
        }

        $haystack = strtolower(trim((string) $message));
        if ($haystack === '') {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function providerLabel(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if ($provider === '' || $provider === 'unknown') {
            return __('The provider');
        }

        return ServerProvider::tryFrom($provider)?->label()
            ?? (string) str($provider)->replace('_', ' ')->title();
    }

    public static function title(string $provider): string
    {
        return __(':provider token was rejected', [
            'provider' => self::providerLabel($provider),
        ]);
    }

    public static function message(string $provider): string
    {
        return __(':provider rejected this API token. Reconnect or add a new token to continue.', [
            'provider' => self::providerLabel($provider),
        ]);
    }
}
