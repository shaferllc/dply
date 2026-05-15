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
        'digitalocean_functions',
        'digitalocean_kubernetes',
        'digitalocean_app_platform',
        'hetzner',
        'vultr',
        'linode',
        'akamai',
        'scaleway',
        'upcloud',
        'equinix_metal',
        'fly_io',
        'aws',
        'aws_app_runner',
        'aws_kubernetes',
        'aws_lambda',
        'custom',
    ];

    /**
     * Providers that are surfaced as "coming soon" in the credentials UI — visible in the
     * sidebar but disabled (no form submission). Set as a constant rather than via env so
     * the placeholder rollout is deterministic in tests.
     *
     * @var list<string>
     */
    private const COMING_SOON = [
        'gandi',
        'namecheap',
        'vercel_dns',
    ];

    public static function enabled(string $provider): bool
    {
        return filter_var(
            config('server_providers.enabled.'.$provider, false),
            FILTER_VALIDATE_BOOL
        );
    }

    /**
     * Whether the provider is rendered as a "coming soon" placeholder (visible in the
     * credentials nav but no functional add-credential form).
     */
    public static function comingSoon(string $provider): bool
    {
        return in_array($provider, self::COMING_SOON, true);
    }

    /**
     * Visible in the credentials sidebar — either fully enabled, or a "coming soon"
     * placeholder. Coming-soon providers don't need the per-provider feature flag.
     */
    public static function visible(string $provider): bool
    {
        return self::enabled($provider) || self::comingSoon($provider);
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
