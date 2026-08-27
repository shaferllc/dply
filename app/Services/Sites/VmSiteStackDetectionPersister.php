<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Actions\Sites\SetSiteRuntime;
use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Modules\Deploy\Contracts\RepositoryFiles;
use App\Modules\Deploy\Services\LaravelComposerPackageDetector;
use App\Modules\Deploy\Services\RepositoryRuntimeDetector;
use App\Modules\Deploy\Support\RemoteRepositoryFiles;
use Illuminate\Support\Facades\Log;

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
 * it now reads through {@see RepositoryFiles} and
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
            'package_manager' => (string) ($result['package_manager'] ?? ''),
            'migration_tool' => (string) ($result['migration_tool'] ?? ''),
            'start_command' => (string) ($result['start_command'] ?? ''),
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

        $correction = $this->correctSiteType($site, $language, $detected);

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['vm_runtime'] = [
            'detected' => $detected,
            'detected_at' => now()->toIso8601String(),
        ] + ($correction !== null ? ['corrected' => $correction] : []);

        $site->forceFill(['meta' => $meta])->save();
    }

    /**
     * Serve the site as what it actually is.
     *
     * Detection has always been written to `meta` and read by the UI; nothing
     * applied it to `type`, which is what chooses the vhost. So a site created
     * as PHP or Static that turns out to be Node kept getting a document-root
     * vhost: `try_files … =404` over a directory with no index, which is a
     * silent 404 on every request with no error anywhere to explain it. The
     * app was fine; nginx was never told to proxy to it.
     *
     * Deliberately one-directional and narrow:
     *
     * - Only away from php/static, never towards them. Demoting a working
     *   proxy to a document root is the failure this is meant to prevent.
     * - Only on the detector's own verdict of the LANGUAGE. Every Laravel app
     *   has a package.json for Vite; "has package.json" would convert them all.
     * - Only when {@see SetSiteRuntime} accepts it — the runtime installed, a
     *   start command, a port, and an app actually present. When it refuses,
     *   the reason is recorded rather than the deploy broken: a wrong guess
     *   here would take a working site down.
     *
     * @param  array<string, mixed>  $detected
     * @return array<string, mixed>|null
     */
    private function correctSiteType(Site $site, string $language, array $detected): ?array
    {
        $current = (string) ($site->runtime ?? '');

        if (! in_array($current, ['', 'php', 'static'], true)) {
            return null;
        }

        if (! in_array($language, ['node', 'python', 'go', 'bun', 'deno', 'java'], true)) {
            return null;
        }

        $changes = ['runtime' => $language];

        // Fill the two things a proxied runtime cannot go without, if the site
        // does not have them yet — the detector knows the start command, and a
        // port is dply's to assign.
        if (trim((string) $site->start_command) === '' && trim((string) $detected['start_command']) !== '') {
            $changes['start_command'] = (string) $detected['start_command'];
        }

        if ((int) ($site->internal_port ?: $site->app_port) <= 0 && $site->server !== null) {
            $port = app(InternalPortAllocator::class)->allocate((string) $site->server_id);

            if ($port !== null) {
                $changes['internal_port'] = (string) $port;
            }
        }

        try {
            app(SetSiteRuntime::class)->handle($site, $changes);
        } catch (\Throwable $e) {
            Log::info('vm runtime: could not correct site type', [
                'site_id' => $site->id,
                'detected' => $language,
                'error' => $e->getMessage(),
            ]);

            return [
                'from' => $current !== '' ? $current : 'unset',
                'to' => $language,
                'applied' => false,
                'reason' => $e->getMessage(),
                'at' => now()->toIso8601String(),
            ];
        }

        return [
            'from' => $current !== '' ? $current : 'unset',
            'to' => $language,
            'applied' => true,
            'at' => now()->toIso8601String(),
        ];
    }
}
