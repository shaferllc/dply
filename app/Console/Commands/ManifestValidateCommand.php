<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Deploy\Manifest\DplyManifestParser;
use App\Services\Sites\ByoRepoConfigLoader;
use Illuminate\Console\Command;

/**
 * Validate a repo `dply.{yaml,yml,json,toml}` manifest — the same parse the
 * deploy pipeline runs, surfaced as a CI-friendly check. Exits non-zero on a
 * hard parse error (and on warnings when --strict).
 */
final class ManifestValidateCommand extends Command
{
    protected $signature = 'dply:manifest:validate
                            {path? : Path to a manifest file or a directory containing one (default: cwd)}
                            {--strict : Treat warnings as failures}';

    protected $description = 'Validate a dply.yaml/.yml/.json/.toml manifest (code-shape + routing/crons/hooks/env).';

    public function handle(DplyManifestParser $parser, ByoRepoConfigLoader $byo): int
    {
        $file = $this->resolveManifestPath($this->argument('path'));
        if ($file === null) {
            $this->error('No dply manifest found. Looked for: '.implode(', ', DplyManifestParser::FILE_NAMES));

            return self::FAILURE;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            $this->error("Could not read manifest: {$file}");

            return self::FAILURE;
        }

        $this->line("Validating <info>{$file}</info>");

        // 1) Code-shape — hard errors throw.
        try {
            $manifest = $parser->parseRaw($raw, $file);
        } catch (\Throwable $e) {
            $this->error('Invalid manifest: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  runtime:   '.($manifest->runtime ?? '(auto-detect)'));
        $this->line('  version:   '.($manifest->version ?? '(auto-detect)'));
        $this->line('  build:     '.(count($manifest->build) ? count($manifest->build).' step(s)' : '(none)'));
        $this->line('  release:   '.(count($manifest->release) ? count($manifest->release).' step(s)' : '(none)'));
        $this->line('  processes: '.(count($manifest->processes) ? implode(', ', array_keys($manifest->processes)) : '(none)'));
        $this->line('  health:    '.($manifest->healthcheck ?? '(none)'));

        // 2) Routing / crons / hooks / env — warnings only.
        $warnings = $manifest->warnings;
        try {
            $parsed = $byo->parse($file, $raw);
            $warnings = array_values(array_unique([...$warnings, ...$parsed['warnings']]));
            $this->line('  redirects: '.count($parsed['config']->redirects));
            $this->line('  crons:     '.count($parsed['crons']).' (+'.count($parsed['server_crons']).' server)');
            $this->line('  hooks:     '.count($parsed['deploy_hooks']));
            $this->line('  env decl:  '.count($parsed['env_declarations']));
        } catch (\Throwable $e) {
            $warnings[] = 'routing/crons section: '.$e->getMessage();
        }

        $this->newLine();
        if ($warnings === []) {
            $this->info('✓ Manifest is valid — no warnings.');

            return self::SUCCESS;
        }

        $this->warn(count($warnings).' warning(s):');
        foreach ($warnings as $w) {
            $this->line('  • '.$w);
        }

        if ($this->option('strict')) {
            $this->error('Failing because --strict and warnings are present.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveManifestPath(?string $path): ?string
    {
        $path = $path !== null && trim($path) !== '' ? rtrim($path, '/') : getcwd();
        if ($path === false) {
            return null;
        }

        if (is_file($path)) {
            return $path;
        }

        if (is_dir($path)) {
            foreach (DplyManifestParser::FILE_NAMES as $name) {
                $candidate = $path.'/'.$name;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
