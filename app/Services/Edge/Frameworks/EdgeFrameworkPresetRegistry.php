<?php

declare(strict_types=1);

namespace App\Services\Edge\Frameworks;

use App\Services\Edge\EdgeBuildRunner;

/**
 * Static registry of every framework dply Edge auto-tunes for. Single
 * source of truth — the Create flow, import wizard, build cache, and
 * template gallery all read from here so adding a framework is a
 * one-touch change.
 *
 * Lookup priority for {@see byDetectionPlan()}:
 *   1. exact framework slug match from detection
 *   2. package.json dependency hint
 *   3. marquer file match (config files, lockfiles)
 *   4. fall back to the `static` preset
 */
class EdgeFrameworkPresetRegistry
{
    /** @var array<string, EdgeFrameworkPreset>|null */
    private static ?array $presets = null;

    /**
     * @return array<string, EdgeFrameworkPreset>
     */
    public static function all(): array
    {
        if (self::$presets !== null) {
            return self::$presets;
        }

        $list = [
            new EdgeFrameworkPreset(
                slug: 'next',
                label: 'Next.js',
                buildCommand: 'npm ci && npm run build',
                outputDir: 'out',
                runtimeMode: EdgeBuildRunner::MODE_SSR,
                cachePaths: ['.next/cache', 'node_modules/.cache'],
                packageDependencies: ['next'],
                marquerFiles: ['next.config.js', 'next.config.mjs', 'next.config.ts'],
                previewOriginRoutes: ['/_next/data/*', '/api/*'],
                docsUrl: 'https://nextjs.org/docs',
            ),
            new EdgeFrameworkPreset(
                slug: 'astro',
                label: 'Astro',
                buildCommand: 'npm ci && npm run build',
                outputDir: 'dist',
                runtimeMode: EdgeBuildRunner::MODE_STATIC,
                cachePaths: ['.astro', 'node_modules/.cache'],
                packageDependencies: ['astro'],
                marquerFiles: ['astro.config.mjs', 'astro.config.ts', 'astro.config.js'],
                docsUrl: 'https://docs.astro.build',
            ),
            new EdgeFrameworkPreset(
                slug: 'sveltekit',
                label: 'SvelteKit',
                buildCommand: 'npm ci && npm run build',
                outputDir: 'build',
                runtimeMode: EdgeBuildRunner::MODE_HYBRID,
                cachePaths: ['.svelte-kit', 'node_modules/.cache'],
                packageDependencies: ['@sveltejs/kit'],
                marquerFiles: ['svelte.config.js', 'svelte.config.ts'],
                previewOriginRoutes: ['/api/*'],
                docsUrl: 'https://kit.svelte.dev/docs',
            ),
            new EdgeFrameworkPreset(
                slug: 'remix',
                label: 'Remix',
                buildCommand: 'npm ci && npm run build',
                outputDir: 'public',
                runtimeMode: EdgeBuildRunner::MODE_HYBRID,
                cachePaths: ['node_modules/.cache', '.cache'],
                packageDependencies: ['@remix-run/dev', '@remix-run/node', '@remix-run/cloudflare'],
                marquerFiles: ['remix.config.js'],
                previewOriginRoutes: ['/api/*'],
                docsUrl: 'https://remix.run/docs',
            ),
            new EdgeFrameworkPreset(
                slug: 'nuxt',
                label: 'Nuxt',
                buildCommand: 'npm ci && npm run generate',
                outputDir: '.output/public',
                runtimeMode: EdgeBuildRunner::MODE_STATIC,
                cachePaths: ['.nuxt', 'node_modules/.cache'],
                packageDependencies: ['nuxt'],
                marquerFiles: ['nuxt.config.ts', 'nuxt.config.js'],
                previewOriginRoutes: ['/api/*'],
                docsUrl: 'https://nuxt.com/docs',
            ),
            new EdgeFrameworkPreset(
                slug: 'vite',
                label: 'Vite',
                buildCommand: 'npm ci && npm run build',
                outputDir: 'dist',
                runtimeMode: EdgeBuildRunner::MODE_STATIC,
                cachePaths: ['node_modules/.cache', '.cache'],
                packageDependencies: ['vite'],
                marquerFiles: ['vite.config.js', 'vite.config.ts'],
                docsUrl: 'https://vitejs.dev',
            ),
            new EdgeFrameworkPreset(
                slug: 'hono',
                label: 'Hono',
                buildCommand: 'npm ci && npm run build',
                outputDir: 'dist',
                runtimeMode: EdgeBuildRunner::MODE_HYBRID,
                cachePaths: ['node_modules/.cache'],
                packageDependencies: ['hono'],
                docsUrl: 'https://hono.dev',
            ),
            new EdgeFrameworkPreset(
                slug: 'eleventy',
                label: 'Eleventy',
                buildCommand: 'npm ci && npx @11ty/eleventy',
                outputDir: '_site',
                runtimeMode: EdgeBuildRunner::MODE_STATIC,
                cachePaths: ['node_modules/.cache'],
                packageDependencies: ['@11ty/eleventy'],
                marquerFiles: ['.eleventy.js', '.eleventy.cjs', 'eleventy.config.js'],
                docsUrl: 'https://www.11ty.dev/docs',
            ),
            new EdgeFrameworkPreset(
                slug: 'hugo',
                label: 'Hugo',
                buildCommand: 'hugo --minify',
                outputDir: 'public',
                runtimeMode: EdgeBuildRunner::MODE_STATIC,
                cachePaths: [],
                marquerFiles: ['hugo.toml', 'hugo.yaml', 'config.toml', 'config.yaml'],
                docsUrl: 'https://gohugo.io/documentation',
            ),
            new EdgeFrameworkPreset(
                slug: 'jekyll',
                label: 'Jekyll',
                buildCommand: 'bundle install && bundle exec jekyll build',
                outputDir: '_site',
                runtimeMode: EdgeBuildRunner::MODE_STATIC,
                cachePaths: ['.jekyll-cache'],
                marquerFiles: ['_config.yml', 'Gemfile'],
                docsUrl: 'https://jekyllrb.com/docs',
            ),
            new EdgeFrameworkPreset(
                slug: 'gatsby',
                label: 'Gatsby',
                buildCommand: 'npm ci && npm run build',
                outputDir: 'public',
                runtimeMode: EdgeBuildRunner::MODE_STATIC,
                cachePaths: ['.cache', 'public/static'],
                packageDependencies: ['gatsby'],
                docsUrl: 'https://www.gatsbyjs.com/docs',
            ),
            new EdgeFrameworkPreset(
                slug: 'static',
                label: 'Plain HTML / Static',
                buildCommand: '',
                outputDir: '.',
                runtimeMode: EdgeBuildRunner::MODE_STATIC,
                cachePaths: [],
                docsUrl: null,
            ),
        ];

        self::$presets = [];
        foreach ($list as $preset) {
            self::$presets[$preset->slug] = $preset;
        }

        return self::$presets;
    }

    public static function find(string $slug): ?EdgeFrameworkPreset
    {
        return self::all()[strtolower(trim($slug))] ?? null;
    }

    /**
     * Best-effort lookup from a {@see RepositoryRuntimePreview} plan
     * array. Returns the `static` fallback when nothing matches so
     * callers can always rely on a non-null preset.
     *
     * @param  array<string, mixed> $plan
     */
    public static function byDetectionPlan(array $plan): EdgeFrameworkPreset
    {
        $slug = strtolower(trim((string) ($plan['framework'] ?? '')));
        if ($slug !== '') {
            $preset = self::find($slug);
            if ($preset !== null) {
                return $preset;
            }
        }

        $depHints = self::lowercaseList($plan['dependencies'] ?? []);
        $fileHints = self::lowercaseList($plan['detected_files'] ?? $plan['files'] ?? []);

        if ($depHints !== []) {
            foreach (self::all() as $preset) {
                foreach ($preset->packageDependencies as $dep) {
                    if (in_array(strtolower($dep), $depHints, true)) {
                        return $preset;
                    }
                }
            }
        }

        if ($fileHints !== []) {
            foreach (self::all() as $preset) {
                foreach ($preset->marquerFiles as $file) {
                    if (in_array(strtolower($file), $fileHints, true)) {
                        return $preset;
                    }
                }
            }
        }

        return self::find('static') ?? throw new \LogicException('static fallback preset is missing.');
    }

    /**
     * @return list<string>
     */
    private static function lowercaseList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($entry) => is_string($entry) ? strtolower(trim($entry)) : null,
            $value,
        )));
    }
}
