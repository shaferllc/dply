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
    public function run(RemoteShell $ssh, Site $site, string $workingDirectory): string
    {
        $site->loadMissing('deploySteps');
        $cwd = escapeshellarg($workingDirectory);
        $log = '';

        foreach ($site->deploySteps->sortBy('sort_order')->values() as $step) {
            /** @var SiteDeployStep $step */
            $cmd = $this->resolveShellCommand($step);
            if ($cmd === null || $cmd === '') {
                continue;
            }
            $timeout = max(30, min(3600, (int) ($step->timeout_seconds ?? 900)));
            $log .= "\n--- pipeline: {$step->step_type} ---\n";
            $log .= $ssh->exec("cd {$cwd} && ({$cmd}) 2>&1", $timeout);
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
