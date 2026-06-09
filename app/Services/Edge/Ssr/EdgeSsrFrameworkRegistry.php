<?php

declare(strict_types=1);

namespace App\Services\Edge\Ssr;

/**
 * Static registry of every framework dply Edge supports in SSR mode.
 * Detection runs `detectInProject()` against the user's package.json
 * and returns the first matching profile — order matters here because
 * a SvelteKit project also has `vite`, so SvelteKit must come first.
 *
 * Adding a framework: drop a new {@see EdgeSsrFrameworkProfile} into
 * {@see all()}. Detection + build runner + uploader all pick it up
 * automatically.
 */
class EdgeSsrFrameworkRegistry
{
    /** @var list<EdgeSsrFrameworkProfile>|null */
    private static ?array $profiles = null;

    /**
     * @return list<EdgeSsrFrameworkProfile>
     */
    public static function all(): array
    {
        return self::$profiles ??= [
            // Next.js — OpenNext bundles `next build` output into a
            // single worker. dply runs OpenNext directly, ignoring
            // the user's build_command, because mis-set commands
            // produce confusing missing-file errors downstream.
            new EdgeSsrFrameworkProfile(
                slug: 'next',
                label: 'Next.js (via @opennextjs/cloudflare)',
                detectDependencies: ['next'],
                adapterDependency: null,
                buildCommandOverride: 'npx --yes @opennextjs/cloudflare@latest build',
                workerPath: '.open-next/worker.js',
                assetsPath: '.open-next/assets',
                entryModule: 'worker.js',
            ),

            // SvelteKit — declared BEFORE Astro because both ship a
            // vite.config.js; we want @sveltejs/kit detection to win.
            // Adapter writes `_worker.js/` as a directory containing
            // index.js plus chunks, so we treat the path as a dir.
            new EdgeSsrFrameworkProfile(
                slug: 'sveltekit',
                label: 'SvelteKit (via @sveltejs/adapter-cloudflare)',
                detectDependencies: ['@sveltejs/kit'],
                adapterDependency: '@sveltejs/adapter-cloudflare',
                buildCommandOverride: null,
                workerPath: '.svelte-kit/cloudflare/_worker.js',
                assetsPath: '.svelte-kit/cloudflare',
                entryModule: 'index.js',
            ),

            // Astro — adapter writes `_worker.js/index.js`. assetsPath
            // is the whole `dist` since the worker dir sits inside it.
            new EdgeSsrFrameworkProfile(
                slug: 'astro',
                label: 'Astro (via @astrojs/cloudflare)',
                detectDependencies: ['astro'],
                adapterDependency: '@astrojs/cloudflare',
                buildCommandOverride: null,
                workerPath: 'dist/_worker.js',
                assetsPath: 'dist',
                entryModule: 'index.js',
            ),

            // Remix — the Cloudflare template builds to
            // `build/client/` (assets) + `build/server/index.js`
            // (worker) with the Vite-based adapter. Older
            // `_worker.js` template variant is not auto-detected;
            // users on it can override `worker_path` in dply.yaml
            // once we surface that escape hatch.
            new EdgeSsrFrameworkProfile(
                slug: 'remix',
                label: 'Remix (via @remix-run/cloudflare)',
                detectDependencies: ['@remix-run/cloudflare'],
                adapterDependency: '@remix-run/cloudflare',
                buildCommandOverride: null,
                workerPath: 'build/server/index.js',
                assetsPath: 'build/client',
                entryModule: 'index.js',
            ),
        ];
    }

    /**
     * Pick the first profile whose `detectDependencies` overlaps
     * with the package.json passed in. Returns null when none match.
     *
     * @param  array<string, mixed>  $packageJson
     */
    public static function detectInProject(array $packageJson): ?EdgeSsrFrameworkProfile
    {
        $declared = self::declaredDependencyNames($packageJson);
        if ($declared === []) {
            return null;
        }

        foreach (self::all() as $profile) {
            foreach ($profile->detectDependencies as $dep) {
                if (in_array($dep, $declared, true)) {
                    return $profile;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $packageJson
     */
    public static function adapterInstalled(EdgeSsrFrameworkProfile $profile, array $packageJson): bool
    {
        if ($profile->adapterDependency === null) {
            return true;
        }

        return in_array($profile->adapterDependency, self::declaredDependencyNames($packageJson), true);
    }

    /**
     * @param  array<string, mixed>  $packageJson
     * @return list<string>
     */
    private static function declaredDependencyNames(array $packageJson): array
    {
        $names = [];
        foreach (['dependencies', 'devDependencies', 'peerDependencies'] as $section) {
            $deps = $packageJson[$section] ?? null;
            if (! is_array($deps)) {
                continue;
            }
            foreach (array_keys($deps) as $name) {
                if (is_string($name)) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }
}
