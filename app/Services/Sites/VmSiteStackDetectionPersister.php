<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Modules\Deploy\Services\LaravelComposerPackageDetector;
use App\Modules\Deploy\Services\RepositoryRuntimeDetector;
use App\Modules\Deploy\Support\RemoteRepositoryFiles;

/**
 * Identify what a VM checkout actually is, and record it on the site.
 *
 * This replaces a composer-only persister that returned early whenever
 * composer.json was absent. The consequence was concrete: a Node repo deployed
 * onto a site seeded as PHP detected nothing, kept its `composer_install` build
 * step, and failed with "php: command not found" on a server with no PHP.
 *
 * There is no second detector here. {@see RepositoryRuntimeDetector} already
 * recognises Laravel, Symfony, Next, Nuxt, Express, Django, Flask, FastAPI, Go
 * and the generic language cases; it simply could not see a remote checkout, so
 * it now reads through {@see \App\Modules\Deploy\Contracts\RepositoryFiles} and
 * this class hands it an SSH-backed implementation. One detector, one
 * vocabulary, every language.
 */
final class VmSiteStackDetectionPersister
{
    public function __construct(
        private readonly RepositoryRuntimeDetector $detector,
        private readonly LaravelComposerPackageDetector $laravelPackages,
    ) {}

    public function persistFromReleasePath(Site $site, RemoteShell $ssh, string $releaseRoot): void
    {
        if (! $site->server?->isVmHost()) {
            return;
        }

        $files = new RemoteRepositoryFiles($ssh, $releaseRoot);

        $result = $this->detector->detect($files, [
            'supports_php_runtime' => true,
            'supports_node_runtime' => true,
            'supports_python_runtime' => true,
            'supports_go_runtime' => true,
        ]);

        $language = strtolower(trim((string) ($result['language'] ?? '')));
        $framework = strtolower(trim((string) ($result['framework'] ?? '')));

        if ($language === '') {
            return;
        }

        $detected = [
            'framework' => $framework !== '' ? $framework : $language.'_generic',
            'language' => $language,
            'confidence' => (string) ($result['confidence'] ?? 'medium'),
            'reasons' => is_array($result['reasons'] ?? null)
                ? $result['reasons']
                : ['Detected during deploy from the repository contents.'],
            'build_command' => (string) ($result['build_command'] ?? ''),
        ];

        // Laravel's per-package flags (octane, horizon, reverb, …) drive the
        // Laravel settings tab, so keep populating them when composer.json is
        // there. Only meaningful for PHP, hence the guard.
        if ($language === 'php') {
            $composer = json_decode((string) $files->read('composer.json'), true);

            if (is_array($composer)) {
                foreach ($this->laravelPackages->flags($composer) as $short => $present) {
                    if ($present) {
                        $detected['laravel_'.$short] = true;
                    }
                }
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
