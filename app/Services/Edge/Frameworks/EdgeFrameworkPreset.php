<?php

declare(strict_types=1);

namespace App\Services\Edge\Frameworks;

/**
 * Canonical Edge-side config for a known framework. Drives Create-flow
 * prefills, the build-runner cache paths, and the import wizard's
 * "translate Vercel/Netlify config to dply" step.
 *
 * One preset per detected framework slug — the slugs match the values
 * RepositoryRuntimePreview returns under `framework`, so detection
 * results map straight into the registry.
 */
final class EdgeFrameworkPreset
{
    /**
     * @param  list<string>  $packageDependencies  Detect-hints — any of these in package.json maps the repo to this preset.
     * @param  list<string>  $marquerFiles  File-presence hints (e.g. `astro.config.mjs`).
     * @param  list<string>  $cachePaths  Additional cache directories on top of node_modules.
     * @param  list<string>  $previewOriginRoutes  Default origin-proxy patterns for hybrid mode.
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly string $buildCommand,
        public readonly string $outputDir,
        public readonly string $runtimeMode,
        public readonly string $nodeVersion = '20',
        public readonly array $cachePaths = [],
        public readonly array $packageDependencies = [],
        public readonly array $marquerFiles = [],
        public readonly array $previewOriginRoutes = [],
        public readonly ?string $docsUrl = null,
    ) {}
}
