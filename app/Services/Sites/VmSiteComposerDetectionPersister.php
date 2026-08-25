<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Modules\Deploy\Services\LaravelComposerPackageDetector;
use App\Modules\Deploy\Services\RepositoryRuntimeDetector;

/**
 * Persists composer-based Laravel stack hints for VM (atomic SSH) deploys so
 * {@see Site::resolvedRuntimeAppDetection()} is populated without Docker/K8s inspection.
 */
final class VmSiteComposerDetectionPersister
{
    public function persistFromReleasePath(Site $site, RemoteShell $ssh, string $releaseRoot): void
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
            // No composer.json. Previously this returned and detection stopped
            // dead, so a Node repo deployed onto a site seeded with PHP steps
            // ran `composer install` and failed with "php: command not found".
            $this->persistNodeFromPackageJson($site, $ssh, $releaseEsc);

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

        /** @var array $detected */
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

    /**
     * Persist Node framework detection from a remote checkout's package.json.
     *
     * The mapping itself is not reimplemented here: RepositoryRuntimeDetector
     * already knows next / nuxt / express and their build commands, and its
     * detectNodeStack() is pure, so we cat the file over SSH and hand it the
     * decoded array.
     */
    private function persistNodeFromPackageJson(Site $site, RemoteShell $ssh, string $releaseEsc): void
    {
        $raw = trim($ssh->exec(
            'if [ -f '.$releaseEsc.'/package.json ]; then cat '.$releaseEsc.'/package.json; fi',
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

        $stack = app(RepositoryRuntimeDetector::class)->detectNodeStack($decoded, []);

        $framework = is_array($stack) ? (string) ($stack['framework'] ?? '') : '';
        if ($framework === '') {
            // package.json with no recognised framework is still a Node app —
            // saying so is what stops the PHP pipeline being assumed.
            $framework = 'node_generic';
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['vm_runtime'] = [
            'detected' => [
                'framework' => $framework,
                'language' => 'node',
                'confidence' => is_array($stack) ? (string) ($stack['confidence'] ?? 'medium') : 'low',
                'reasons' => is_array($stack) && is_array($stack['reasons'] ?? null)
                    ? $stack['reasons']
                    : ['Detected package.json during deploy.'],
                'detected_files' => ['package.json'],
                'build_command' => is_array($stack) ? (string) ($stack['build_command'] ?? '') : '',
            ],
            'detected_at' => now()->toIso8601String(),
        ];

        $site->forceFill(['meta' => $meta])->save();
    }
}
