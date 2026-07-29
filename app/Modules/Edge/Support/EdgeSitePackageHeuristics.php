<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

/**
 * Distinguishes deployable Edge app package.json files from framework /
 * tooling monorepo roots (e.g. withastro/astro, vercel/next.js) that match
 * a framework keyword but never emit a site `dist/` at the repo root.
 */
final class EdgeSitePackageHeuristics
{
    /**
     * Direct dependencies that indicate a real site package (not a
     * workspace orchestration root).
     *
     * @var list<string>
     */
    private const SITE_FRAMEWORK_DEPS = [
        'astro',
        'next',
        'nuxt',
        'vite',
        'gatsby',
        '@sveltejs/kit',
        '@11ty/eleventy',
        'vitepress',
        '@docusaurus/core',
        'remix',
        '@remix-run/node',
        'react-scripts',
        'vue-cli-service',
        '@vue/cli-service',
    ];

    /**
     * @param  array<string, mixed>  $pkg  Parsed package.json
     */
    public static function looksLikeNonDeployablePackageRoot(array $pkg): bool
    {
        if (! self::hasNpmWorkspaces($pkg) && ! self::hasMonorepoOrchestrationBuild($pkg)) {
            return false;
        }

        // App packages (examples/basics, apps/web) declare a versioned
        // framework dep and a site-shaped build — keep those eligible.
        if (self::hasVersionedSiteFrameworkDependency($pkg)) {
            return false;
        }

        // Framework / turborepo roots: workspaces or turbo/lerna/nx build,
        // but no installable site framework at this package.json.
        return self::hasNpmWorkspaces($pkg) || self::hasMonorepoOrchestrationBuild($pkg);
    }

    /**
     * @param  array<string, mixed>  $pkg
     */
    public static function hasNpmWorkspaces(array $pkg): bool
    {
        $workspaces = $pkg['workspaces'] ?? null;
        if (is_array($workspaces)) {
            if ($workspaces === []) {
                return false;
            }

            // npm: "workspaces": ["packages/*"]
            if (array_is_list($workspaces)) {
                return true;
            }

            // yarn: "workspaces": { "packages": ["apps/*"] }
            $packages = $workspaces['packages'] ?? null;

            return is_array($packages) && $packages !== [];
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $pkg
     */
    public static function hasMonorepoOrchestrationBuild(array $pkg): bool
    {
        $scripts = is_array($pkg['scripts'] ?? null) ? $pkg['scripts'] : [];
        $build = is_string($scripts['build'] ?? null) ? $scripts['build'] : '';
        if ($build === '') {
            return false;
        }

        return preg_match(
            '/\b(turbo|lerna|nx)\b|--filter=|pnpm\s+-r\b|pnpm\s+--recursive\b|yarn\s+workspaces\b/',
            $build,
        ) === 1;
    }

    /**
     * @param  array<string, mixed>  $pkg
     */
    public static function hasVersionedSiteFrameworkDependency(array $pkg): bool
    {
        $deps = array_merge(
            is_array($pkg['dependencies'] ?? null) ? $pkg['dependencies'] : [],
            is_array($pkg['devDependencies'] ?? null) ? $pkg['devDependencies'] : [],
        );

        foreach (self::SITE_FRAMEWORK_DEPS as $name) {
            if (! isset($deps[$name])) {
                continue;
            }

            $version = strtolower(trim((string) $deps[$name]));
            if ($version === '' || str_starts_with($version, 'workspace:')) {
                continue;
            }

            return true;
        }

        return false;
    }
}
