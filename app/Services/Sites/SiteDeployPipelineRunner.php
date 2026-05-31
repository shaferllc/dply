<?php

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Models\SiteDeployStep;

/**
 * Runs ordered {@see SiteDeployStep} records over SSH in the deploy working directory.
 */
class SiteDeployPipelineRunner
{
    public function __construct(
        private readonly DeployHookRunner $hookRunner,
    ) {}

    public function run(RemoteShell $ssh, Site $site, string $workingDirectory): string
    {
        return $this->runBuild($ssh, $site, $workingDirectory)
            .$this->runRelease($ssh, $site, $workingDirectory);
    }

    public function runBuild(RemoteShell $ssh, Site $site, string $workingDirectory): string
    {
        return $this->runPhase($ssh, $site, $workingDirectory, SiteDeployStep::PHASE_BUILD);
    }

    public function runRelease(RemoteShell $ssh, Site $site, string $workingDirectory): string
    {
        return $this->runPhase($ssh, $site, $workingDirectory, SiteDeployStep::PHASE_RELEASE);
    }

    protected function runPhase(RemoteShell $ssh, Site $site, string $workingDirectory, string $phase): string
    {
        $site->loadMissing('deploySteps');
        $cwd = escapeshellarg($workingDirectory);
        $log = '';

        $steps = $site->deploySteps
            ->where('phase', $phase)
            ->sortBy('sort_order')
            ->values();

        foreach ($steps as $step) {
            /** @var SiteDeployStep $step */
            $cmd = $this->resolveShellCommand($step);
            if ($cmd === null || $cmd === '') {
                $log .= $this->hookRunner->runAfterStep($ssh, $site, (string) $step->id, $workingDirectory);

                continue;
            }
            $timeout = max(30, min(3600, (int) ($step->timeout_seconds ?? 900)));
            $log .= "\n--- pipeline ({$phase}): {$step->step_type} ---\n";
            $log .= $ssh->exec("cd {$cwd} && ({$cmd}) 2>&1", $timeout);
            $log .= $this->hookRunner->runAfterStep($ssh, $site, (string) $step->id, $workingDirectory);
        }

        return $log;
    }

    protected function resolveShellCommand(SiteDeployStep $step): ?string
    {
        return SiteDeployPipelineCommands::fragmentFor(
            $step->step_type,
            trim((string) ($step->custom_command ?? ''))
        );
    }
}
