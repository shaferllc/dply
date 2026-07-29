<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Ssr;

use App\Modules\Edge\Services\EdgeBuildRunner;

/**
 * Build profile for a single SSR-capable framework. Drives the SSR
 * branch in {@see EdgeBuildRunner} — what to run,
 * what worker output to look for, what assets to copy.
 *
 * Add a framework: append a profile to {@see EdgeSsrFrameworkRegistry}.
 * No changes needed elsewhere — the build runner reads from here.
 */
final class EdgeSsrFrameworkProfile
{
    /**
     * @param  array<string, mixed> $detectDependencies  Any one match in package.json triggers this profile.
     * @param  ?string  $adapterDependency  Required adapter package for the build to succeed (null when the framework itself is the adapter, e.g. Next.js relies on OpenNext run by dply).
     * @param  ?string  $buildCommandOverride  Shell command dply runs in place of the user's build_command. Null = run the user's command unchanged.
     * @param  string  $workerPath  File or directory (relative to checkout) where the bundled Worker lands.
     * @param  string  $assetsPath  Relative path containing the static assets layer.
     * @param  string  $entryModule  Module file name inside $workerPath when it's a directory (ignored when $workerPath is a single file).
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly array $detectDependencies,
        public readonly ?string $adapterDependency,
        public readonly ?string $buildCommandOverride,
        public readonly string $workerPath,
        public readonly string $assetsPath,
        public readonly string $entryModule = 'worker.js',
    ) {}
}
