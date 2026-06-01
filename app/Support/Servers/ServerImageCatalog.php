<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;

/**
 * Read-only helper over config/server_images.php.
 *
 * Maps the wizard's provider-agnostic OS image keys (e.g. "ubuntu-24-04") to
 * the per-provider slugs their APIs expect, and back to human labels for the
 * picker and review screens. Providers that have no slug for a chosen image
 * resolve to null here so the provisioning job can fall back to its
 * services.php default — picking an unsupported image never breaks provisioning.
 */
final class ServerImageCatalog
{
    /**
     * @return array<string, array{label: string, family: string, slugs: array<string, string>}>
     */
    public static function images(): array
    {
        /** @var array<string, array{label: string, family: string, slugs: array<string, string>}> $images */
        $images = config('server_images.images', []);

        return $images;
    }

    /**
     * Global default image key (used when the selected provider supports it).
     */
    public static function defaultKey(): string
    {
        return (string) config('server_images.default', '');
    }

    /**
     * Whether the provider offers at least one image in the catalog — drives
     * whether the wizard renders the OS picker for it at all.
     */
    public static function supportsProvider(string $provider): bool
    {
        return self::optionsForProvider($provider) !== [];
    }

    /**
     * Whether a specific image key is offered for the given provider.
     */
    public static function isValidForProvider(string $provider, string $key): bool
    {
        $slug = self::images()[$key]['slugs'][$provider] ?? null;

        return is_string($slug) && $slug !== '';
    }

    /**
     * Picker options for a provider, in catalog order, shaped for the wizard's
     * `_rich-select` partial.
     *
     * @return list<array{id: string, label: string, summary: string, family: string}>
     */
    public static function optionsForProvider(string $provider): array
    {
        $options = [];
        foreach (self::images() as $key => $image) {
            $slug = $image['slugs'][$provider] ?? null;
            if (! is_string($slug) || $slug === '') {
                continue;
            }
            $family = (string) ($image['family'] ?? '');
            $options[] = [
                'id' => $key,
                'label' => (string) ($image['label'] ?? $key),
                'summary' => $family === 'debian' ? __('Debian') : __('Ubuntu'),
                'family' => $family,
            ];
        }

        return $options;
    }

    /**
     * Allowed image keys for a provider — handy for Rule::in() validation.
     *
     * @return list<string>
     */
    public static function allowedKeysForProvider(string $provider): array
    {
        return array_map(static fn (array $option): string => $option['id'], self::optionsForProvider($provider));
    }

    /**
     * The image key to pre-select for a provider: the global default when the
     * provider supports it, otherwise the first image it does offer, otherwise
     * an empty string (provider has no catalog entries).
     */
    public static function defaultKeyForProvider(string $provider): string
    {
        $default = self::defaultKey();
        if ($default !== '' && self::isValidForProvider($provider, $default)) {
            return $default;
        }

        return self::optionsForProvider($provider)[0]['id'] ?? '';
    }

    /**
     * Resolve a provider-agnostic image key to the provider's native slug, or
     * null when the key is blank/unknown or the provider has no slug for it.
     */
    public static function resolveSlug(string $provider, ?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $slug = self::images()[$key]['slugs'][$provider] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * Resolve the native image slug for a server's chosen OS image (stored on
     * `meta.os_image`), or null when none was chosen / it doesn't apply to the
     * provider. Provisioning jobs call this and fall back to config on null.
     */
    public static function resolveForServer(Server $server, string $provider): ?string
    {
        $meta = $server->meta;
        $key = is_array($meta) ? ($meta['os_image'] ?? null) : null;

        return self::resolveSlug($provider, is_string($key) ? $key : null);
    }

    /**
     * Human label for an image key (for review/summary screens), or null when
     * the key is blank/unknown.
     */
    public static function labelFor(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $label = self::images()[$key]['label'] ?? null;

        return is_string($label) ? $label : null;
    }
}
