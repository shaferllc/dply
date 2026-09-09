<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Models\Organization;
use App\Models\Server;
use Throwable;

/**
 * Region + size catalog for a dedicated VM that should sit next to an app
 * server. Remaps stale DigitalOcean city codes (`sfo`) and unavailable
 * numbered slugs (`sfo2` when the account only has `sfo3`) before create.
 */
final class DedicatedVmPlacement
{
    /**
     * @return array{
     *     region: string,
     *     requested_region: string,
     *     remapped: bool,
     *     sizes: list<array{value: string, label: string}>,
     *     error: ?string
     * }
     */
    public static function for(Server $appServer, Organization $org): array
    {
        $provider = $appServer->provider->value;
        $requested = (string) $appServer->region;
        $region = ProviderComputeRegion::normalize($provider, $requested);
        $sizes = [];
        $error = null;

        try {
            $catalog = app(ResolveServerCreateCatalog::class)->handle(
                $org,
                $provider,
                (string) $appServer->provider_credential_id,
                $region,
                fallbackToGlobalCatalog: true,
            );
            $available = collect($catalog['regions'] ?? [])
                ->pluck('value')
                ->filter()
                ->map(static fn (mixed $value): string => (string) $value)
                ->values()
                ->all();
            $region = ProviderComputeRegion::coerceAvailable($provider, $region, $available);

            if ($region !== ProviderComputeRegion::normalize($provider, $requested)) {
                $catalog = app(ResolveServerCreateCatalog::class)->handle(
                    $org,
                    $provider,
                    (string) $appServer->provider_credential_id,
                    $region,
                    fallbackToGlobalCatalog: true,
                );
            }

            $sizes = collect($catalog['sizes'] ?? [])
                ->map(static fn (mixed $size): array => [
                    'value' => (string) ($size['value'] ?? ''),
                    'label' => (string) ($size['label'] ?? ''),
                ])
                ->filter(static fn (array $size): bool => $size['value'] !== '')
                ->values()
                ->all();
            $error = isset($catalog['error']) && is_string($catalog['error']) && $catalog['error'] !== ''
                ? $catalog['error']
                : null;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'region' => $region,
            'requested_region' => $requested,
            'remapped' => $region !== '' && $region !== $requested,
            'sizes' => $sizes,
            'error' => $error,
        ];
    }

    /**
     * @param  list<array{value: string, label: string}>  $sizes
     */
    public static function assertSizeAvailable(string $size, array $sizes, string $region): void
    {
        if ($size === '' || $sizes === []) {
            return;
        }

        $values = array_column($sizes, 'value');
        if (! in_array($size, $values, true)) {
            throw new \InvalidArgumentException(__('Size :size is not available in :region. Pick another size.', [
                'size' => $size,
                'region' => $region,
            ]));
        }
    }
}
