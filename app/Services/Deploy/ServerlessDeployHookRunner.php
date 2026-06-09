<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Services\Sites\DeployHookRunner;
use App\Services\Sites\DeployHookScriptExpander;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs a serverless function's deploy hooks.
 *
 * The VM {@see DeployHookRunner} executes hooks over SSH
 * on the server. A function has no server — its artifact is built on dply's
 * own build host (the same place `composer install` already runs) — so this
 * runner executes each hook as a local shell process in the build's working
 * directory. A non-zero exit aborts the deploy.
 */
final class ServerlessDeployHookRunner
{
    /** Phases run during the artifact build, in pipeline order. */
    public const PHASE_LABELS = [
        SiteDeployHook::PHASE_BEFORE_CLONE => 'Before build',
        SiteDeployHook::PHASE_AFTER_CLONE => 'After build',
        SiteDeployHook::PHASE_AFTER_ACTIVATE => 'After deploy',
    ];

    public function __construct(private readonly DeployHookScriptExpander $expander) {}

    /**
     * Run every hook configured for the phase, in sort order, returning the
     * combined transcript. Returns an empty string when the site has none.
     */
    public function runPhase(Site $site, string $phase, string $workingDirectory): string
    {
        $site->loadMissing('deployHooks');

        $hooks = $site->deployHooks
            ->where('phase', $phase)
            ->sortBy('sort_order')
            ->values();

        $log = [];
        foreach ($hooks as $hook) {
            $script = trim((string) $hook->script);
            if ($script === '') {
                continue;
            }

            $log[] = $this->runScript(
                $this->expander->expand($script, $site),
                $workingDirectory,
                $this->timeoutFor($hook),
                $phase,
                (string) $hook->id,
            );
        }

        return implode("\n", $log);
    }

    private function runScript(string $script, string $cwd, int $timeout, string $phase, string $hookId): string
    {
        $process = Process::fromShellCommandline($script, $cwd);
        $process->setTimeout($timeout);
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        $header = '--- hook '.$phase.' #'.$hookId.' ---';

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                $header."\nDeploy hook failed with exit ".$process->getExitCode().":\n".$output
            );
        }

        return $header."\n".$output;
    }

    private function timeoutFor(SiteDeployHook $hook): int
    {
        $default = (int) config('dply.default_deploy_hook_timeout_seconds', 900);

        return max(30, min(3600, (int) ($hook->timeout_seconds ?? $default)));
    }
}
