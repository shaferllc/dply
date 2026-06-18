<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Console;

use App\Modules\Deploy\Services\RuntimeDetection\GitCloneException;
use App\Modules\Deploy\Services\RuntimeDetection\RepositoryRuntimePlan;
use App\Modules\Deploy\Services\RuntimeDetection\RepositoryRuntimePreview;
use Illuminate\Console\Command;

/**
 * Dogfood entry point for the runtime-detection layer.
 *
 * Runs RepositoryRuntimePlanComposer against a checked-out repo and
 * prints the merged plan (manifest + detection) so the same code path
 * the site-create flow will use can be exercised from a terminal.
 *
 * Use --json to produce a machine-readable shape for scripts and
 * fixture-generation. Default output is human-readable, with each
 * field annotated by source (manifest / detection / default).
 */
class DetectRuntimeCommand extends Command
{
    protected $signature = 'dply:detect-runtime
        {path? : Path to a checked-out repository (defaults to current directory)}
        {--url= : Clone a remote repository for detection (mutually exclusive with path)}
        {--branch=main : Branch to clone when using --url}
        {--json : Output the plan as JSON}';

    protected $description = 'Run runtime detection + dply.yaml manifest composition against a local repo or a remote URL and print the resulting plan.';

    public function handle(RepositoryRuntimePreview $preview): int
    {
        $url = (string) ($this->option('url') ?? '');
        $rawPath = (string) ($this->argument('path') ?? '');

        if ($url !== '' && $rawPath !== '') {
            $this->error('Pass either a path or --url, not both.');

            return self::FAILURE;
        }

        if ($url !== '') {
            return $this->handleUrl($preview, $url, (string) ($this->option('branch') ?? 'main'));
        }

        $path = $rawPath !== '' ? $rawPath : getcwd();

        if ($path === false || ! is_dir($path)) {
            $this->error("Path is not a directory: {$rawPath}");

            return self::FAILURE;
        }

        $absolute = realpath($path);
        if ($absolute === false) {
            $this->error("Could not resolve path: {$path}");

            return self::FAILURE;
        }

        $plan = $preview->fromPath($absolute);

        if ($plan === null) {
            if ($this->option('json')) {
                $this->line(json_encode(['plan' => null, 'path' => $absolute], JSON_PRETTY_PRINT));
            } else {
                $this->warn("No runtime detected at {$absolute}.");
                $this->line('No dply.yaml manifest, no recognized runtime signals.');
            }

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($this->planToArray($plan, $absolute), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHumanReadable($plan, $absolute);

        return self::SUCCESS;
    }

    private function handleUrl(RepositoryRuntimePreview $preview, string $url, string $branch): int
    {
        try {
            $plan = $preview->fromUrl($url, $branch);
        } catch (GitCloneException $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'plan' => null,
                    'url' => $url,
                    'branch' => $branch,
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        $label = "{$url}@{$branch}";

        if ($plan === null) {
            if ($this->option('json')) {
                $this->line(json_encode(['plan' => null, 'url' => $url, 'branch' => $branch], JSON_PRETTY_PRINT));
            } else {
                $this->warn("No runtime detected at {$label}.");
                $this->line('No dply.yaml manifest, no recognized runtime signals.');
            }

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($this->planToArray($plan, $label), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHumanReadable($plan, $label);

        return self::SUCCESS;
    }

    private function renderHumanReadable(RepositoryRuntimePlan $plan, string $absolute): void
    {
        $this->newLine();
        $this->line("<fg=cyan>Runtime plan for</> <fg=white;options=bold>{$absolute}</>");
        $this->newLine();

        $rows = [
            ['runtime', $plan->runtime, $plan->fieldSource('runtime')],
            ['version', $plan->version ?? '<unset>', $plan->fieldSource('version')],
            ['framework', $plan->framework ?? '<none>', 'detection'],
            ['build_command', $plan->buildCommand ?? '<unset>', $plan->fieldSource('build_command')],
            ['start_command', $plan->startCommand ?? '<unset>', $plan->fieldSource('start_command')],
            ['app_port', $plan->appPort !== null ? (string) $plan->appPort : '<unset>', $plan->fieldSource('app_port')],
            ['confidence', $plan->confidence, '—'],
        ];

        $this->table(['field', 'value', 'source'], $rows);

        if ($plan->processes !== []) {
            $this->newLine();
            $this->line('<fg=cyan>Suggested processes:</>');
            $processRows = [];
            foreach ($plan->processes as $process) {
                $processRows[] = [$process->type, $process->name, $process->command];
            }
            $this->table(['type', 'name', 'command'], $processRows);
        }

        if ($plan->reasons !== []) {
            $this->newLine();
            $this->line('<fg=cyan>Reasons:</>');
            foreach ($plan->reasons as $reason) {
                $this->line("  • {$reason}");
            }
        }

        if ($plan->warnings !== []) {
            $this->newLine();
            $this->line('<fg=yellow>Warnings:</>');
            foreach ($plan->warnings as $warning) {
                $this->line("  • {$warning}");
            }
        }

        $this->newLine();
    }

    /**
     * @return array<string, mixed>
     */
    private function planToArray(RepositoryRuntimePlan $plan, string $absolute): array
    {
        return [
            'path' => $absolute,
            'plan' => [
                'runtime' => $plan->runtime,
                'version' => $plan->version,
                'framework' => $plan->framework,
                'build_command' => $plan->buildCommand,
                'start_command' => $plan->startCommand,
                'app_port' => $plan->appPort,
                'confidence' => $plan->confidence,
                'sources' => $plan->sources,
                'processes' => array_map(fn ($p) => [
                    'type' => $p->type,
                    'name' => $p->name,
                    'command' => $p->command,
                    'reason' => $p->reason,
                ], $plan->processes),
                'reasons' => $plan->reasons,
                'warnings' => $plan->warnings,
                'has_manifest' => $plan->hasManifest(),
            ],
        ];
    }
}
