<?php

declare(strict_types=1);

namespace App\Services\Deploy;

/**
 * First-party Laravel Composer packages we surface in site runtime settings when detected.
 *
 * @phpstan-type LaravelPackageFlags array{
 *     octane: bool,
 *     horizon: bool,
 *     pulse: bool,
 *     reverb: bool
 * }
 */
final class LaravelComposerPackageDetector
{
    /**
     * Short keys used in UI and meta; values are composer package names.
     *
     * @var array<string, string>
     */
    public const PACKAGE_KEYS = [
        'octane' => 'laravel/octane',
        'horizon' => 'laravel/horizon',
        'pulse' => 'laravel/pulse',
        'reverb' => 'laravel/reverb',
    ];

    /**
     * @return LaravelPackageFlags
     */
    public function flags(?array $composerJson): array
    {
        $out = [
            'octane' => false,
            'horizon' => false,
            'pulse' => false,
            'reverb' => false,
        ];

        if ($composerJson === null) {
            return $out;
        }

        foreach (self::PACKAGE_KEYS as $key => $package) {
            $out[$key] = $this->composerRequiresPackage($composerJson, $package);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $composerJson
     */
    private function composerRequiresPackage(array $composerJson, string $package): bool
    {
        $require = is_array($composerJson['require'] ?? null) ? $composerJson['require'] : [];
        $requireDev = is_array($composerJson['require-dev'] ?? null) ? $composerJson['require-dev'] : [];

        return array_key_exists($package, $require) || array_key_exists($package, $requireDev);
    }
}
