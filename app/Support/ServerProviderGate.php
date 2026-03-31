<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether a provider is exposed in UI and accepted for new credentials / server create.
 */
final class ServerProviderGate
{
    /**
     * @var list<string>
     */
    private const SERVER_CREATE_ORDER = [
        'digitalocean',
        'hetzner',
        'vultr',
        'linode',
        'akamai',
        'scaleway',
        'upcloud',
        'equinix_metal',
        'fly_io',
        'aws',
        'custom',
    ];

    public static function enabled(string $provider): bool
    {
        return filter_var(
            config('server_providers.enabled.'.$provider, false),
            FILTER_VALIDATE_BOOL
        );
    }

    public static function defaultServerCreateType(): string
    {
        foreach (self::SERVER_CREATE_ORDER as $id) {
            if (self::enabled($id)) {
                return $id;
            }
        }

        return self::enabled('custom') ? 'custom' : 'digitalocean';
    }
}
