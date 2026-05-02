<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Catalog command — lists frameworks dply auto-detects, grouped by
 * runtime. Single source of truth lives next to the runtime
 * detectors but mirrored here so operators can discover it from
 * the terminal without grepping the codebase.
 *
 *   dply:list-frameworks            # human grouped table
 *   dply:list-frameworks --runtime=python
 *   dply:list-frameworks --json
 *
 * If you ship a new framework detector, update FRAMEWORKS_BY_RUNTIME
 * here too — the dashboard wizard tag pills should stay in sync.
 */
class ListFrameworksCommand extends Command
{
    protected $signature = 'dply:list-frameworks
        {--runtime= : Filter to a specific runtime}
        {--json : Output as JSON}';

    protected $description = 'List frameworks dply auto-detects, grouped by runtime.';

    /**
     * Mirrors detector logic. Each entry is [framework => one-line summary].
     *
     * Update when adding a new detector branch in
     * App\Services\Deploy\RuntimeDetection\*RuntimeDetector.
     *
     * @var array<string, array<string, string>>
     */
    private const FRAMEWORKS_BY_RUNTIME = [
        'php' => [
            'laravel' => 'composer.json requires laravel/framework',
            'symfony' => 'composer.json requires symfony/framework-bundle',
            'php' => 'fallback when composer.json present but no framework matched',
        ],
        'node' => [
            'next' => 'package.json depends on next',
            'nuxt' => 'package.json depends on nuxt',
            'remix' => 'package.json depends on @remix-run/dev',
            'sveltekit' => 'package.json depends on @sveltejs/kit',
            'vite' => 'package.json depends on vite (SPA fallback)',
            'node' => 'fallback when package.json present',
        ],
        'python' => [
            'django' => 'requirements include Django',
            'fastapi' => 'requirements include fastapi',
            'flask' => 'requirements include Flask',
            'python' => 'fallback when requirements.txt or pyproject.toml present',
        ],
        'ruby' => [
            'rails' => 'Gemfile requires rails',
            'sinatra' => 'Gemfile requires sinatra (with config.ru)',
            'ruby' => 'fallback when Gemfile present',
        ],
        'go' => [
            'go' => 'go.mod present',
        ],
        'static' => [
            'jekyll' => '_config.yml + Gemfile with jekyll',
            'hugo' => 'config.toml/yaml/json with theme directory',
            'eleventy' => '.eleventy.js or eleventy.config.js',
            'static' => 'fallback for plain HTML/CSS sites',
        ],
    ];

    public function handle(): int
    {
        $runtime = $this->option('runtime');
        $catalog = self::FRAMEWORKS_BY_RUNTIME;
        if ($runtime !== null) {
            if (! isset($catalog[$runtime])) {
                $this->error(sprintf(
                    'Unknown runtime "%s". Allowed: %s',
                    $runtime,
                    implode(', ', array_keys($catalog)),
                ));

                return self::FAILURE;
            }
            $catalog = [$runtime => $catalog[$runtime]];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'runtime_filter' => $runtime,
                'frameworks_by_runtime' => $catalog,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($catalog as $runtimeKey => $frameworks) {
            $this->newLine();
            $this->line('<fg=cyan>'.ucfirst($runtimeKey).'</>');
            foreach ($frameworks as $name => $note) {
                $this->line(sprintf('  %-12s %s', $name, '<fg=gray>'.$note.'</>'));
            }
        }
        $this->newLine();
        $this->line('<fg=gray>Detection lives in App\\Services\\Deploy\\RuntimeDetection\\*Detector.</>');

        return self::SUCCESS;
    }
}
