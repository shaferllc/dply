<?php

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Models\SiteDeployStep;

/**
 * Runs ordered {@see SiteDeployStep} records over SSH in the deploy working directory.
 *
 * Each phase method returns a structured result so callers can both append
 * the human-readable log AND record per-step status/timing onto the
 * {@see \App\Models\SiteDeployment} (powering the live phase timeline):
 *
 *   ['log' => string, 'steps' => list<step>, 'ok' => bool]
 *
 * where each step matches the shape {@see \App\Services\Deploy\DeployPhaseRunner}
 * records: {step_id, step_type, command, ok, output, duration_ms, skipped}.
 * The runner does NOT throw on a failed step — it sets ok=false and stops the
 * phase so the caller can record the partial results before failing the deploy.
 */
class SiteDeployPipelineRunner
{
    public function __construct(
        private readonly DeployHookRunner $hookRunner,
    ) {}

    /**
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    public function run(RemoteShell $ssh, Site $site, string $workingDirectory): array
    {
        $build = $this->runBuild($ssh, $site, $workingDirectory);
        $release = $this->runRelease($ssh, $site, $workingDirectory);

        return [
            'log' => $build['log'].$release['log'],
            'steps' => [...$build['steps'], ...$release['steps']],
            'ok' => $build['ok'] && $release['ok'],
        ];
    }

    /**
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    public function runBuild(RemoteShell $ssh, Site $site, string $workingDirectory): array
    {
        return $this->runPhase($ssh, $site, $workingDirectory, SiteDeployStep::PHASE_BUILD);
    }

    /**
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    public function runRelease(RemoteShell $ssh, Site $site, string $workingDirectory): array
    {
        return $this->runPhase($ssh, $site, $workingDirectory, SiteDeployStep::PHASE_RELEASE);
    }

    /**
     * @return array{log: string, steps: list<array<string, mixed>>, ok: bool}
     */
    protected function runPhase(RemoteShell $ssh, Site $site, string $workingDirectory, string $phase): array
    {
        $site->loadMissing('deploySteps');
        $cwd = escapeshellarg($workingDirectory);
        $log = '';
        $steps = [];
        $ok = true;

        $ordered = $site->deploySteps
            ->where('phase', $phase)
            ->sortBy('sort_order')
            ->values();

        foreach ($ordered as $step) {
            /** @var SiteDeployStep $step */
            $cmd = $this->resolveShellCommand($step);
            if ($cmd === null || $cmd === '') {
                // A step with no resolvable command (e.g. an empty custom
                // step) is a no-op — record it as skipped so the timeline
                // still accounts for it, and run its after-step hooks.
                $hookLog = $this->hookRunner->runAfterStep($ssh, $site, (string) $step->id, $workingDirectory);
                $log .= $hookLog;
                $steps[] = [
                    'step_id' => (string) $step->id,
                    'step_type' => (string) $step->step_type,
                    'command' => null,
                    'ok' => true,
                    'output' => $hookLog,
                    'duration_ms' => 0,
                    'skipped' => true,
                ];

                continue;
            }

            $timeout = max(30, min(3600, (int) ($step->timeout_seconds ?? 900)));
            $header = "\n--- pipeline ({$phase}): {$step->step_type} ---\n";
            $log .= $header;
            // SSH exec does not surface non-zero exit codes, so append an exit
            // marker and read it back — otherwise a failed build/migration
            // would be recorded (and shown on the timeline) as success.
            $start = microtime(true);
            $stepOut = $ssh->exec(
                sprintf('cd %s && (%s) 2>&1; printf "\nDPLY_STEP_EXIT:%%s" "$?"', $cwd, $cmd),
                $timeout
            );
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $log .= $stepOut;
            $hookLog = $this->hookRunner->runAfterStep($ssh, $site, (string) $step->id, $workingDirectory);
            $log .= $hookLog;

            $stepOk = $this->outputSucceeded($stepOut) && $this->outputSucceeded($hookLog);
            $steps[] = [
                'step_id' => (string) $step->id,
                'step_type' => (string) $step->step_type,
                'command' => $cmd,
                'ok' => $stepOk,
                'output' => $stepOut.$hookLog,
                'duration_ms' => $durationMs,
                'skipped' => false,
            ];

            if (! $stepOk) {
                // Abort the phase on first failure so later steps don't pile
                // onto broken state. The caller records these results, then
                // fails the deploy.
                $ok = false;
                break;
            }
        }

        return ['log' => $log, 'steps' => $steps, 'ok' => $ok];
    }

    /**
     * Read the appended exit markers (step + after-step hooks) and report
     * whether everything exited 0. Output with no marker (legacy commands,
     * fake shells in tests) is treated as success.
     */
    protected function outputSucceeded(string $output): bool
    {
        if (preg_match_all('/DPLY_(?:STEP|HOOK)_EXIT:(\d+)/', $output, $m)) {
            foreach ($m[1] as $code) {
                if ((int) $code !== 0) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function resolveShellCommand(SiteDeployStep $step): ?string
    {
        return SiteDeployPipelineCommands::fragmentFor(
            $step->step_type,
            trim((string) ($step->custom_command ?? ''))
        );
    }
}
