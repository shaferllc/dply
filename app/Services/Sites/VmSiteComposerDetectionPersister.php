<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Deploy\LaravelComposerPackageDetector;

/**
 * Persists composer-based Laravel stack hints for VM (atomic SSH) deploys so
 * {@see Site::resolvedRuntimeAppDetection()} is populated without Docker/K8s inspection.
 */
final class VmSiteComposerDetectionPersister
{
    public function persistFromReleasePath(Site $site, SshConnection $ssh, string $releaseRoot): void
    {
        if (! $site->server?->isVmHost()) {
            return;
        }

        $releaseEsc = escapeshellarg($releaseRoot);
        $raw = trim($ssh->exec(
            'if [ -f '.$releaseEsc.'/composer.json ]; then cat '.$releaseEsc.'/composer.json; fi',
            60
        ));

        if ($raw === '') {
            return;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return;
        }

        $require = is_array($decoded['require'] ?? null) ? $decoded['require'] : [];
        $hasLaravelFramework = array_key_exists('laravel/framework', $require);
        $hasArtisan = trim($ssh->exec('test -f '.$releaseEsc.'/artisan && echo 1', 15)) === '1';

        if (! $hasLaravelFramework && ! $hasArtisan) {
            return;
        }

        $flags = app(LaravelComposerPackageDetector::class)->flags($decoded);

        /** @var array<string, mixed> $detected */
        $detected = [
            'framework' => 'laravel',
            'language' => 'php',
            'confidence' => 'medium',
            'reasons' => ['Detected from composer.json during deploy.'],
            'detected_files' => ['composer.json'],
        ];

        foreach (LaravelComposerPackageDetector::PACKAGE_KEYS as $short => $_pkg) {
            if (! empty($flags[$short])) {
                $detected['laravel_'.$short] = true;
            }
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['vm_runtime'] = [
            'detected' => $detected,
            'detected_at' => now()->toIso8601String(),
        ];

        $site->forceFill(['meta' => $meta])->save();
    }
}
