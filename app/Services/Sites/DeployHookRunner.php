<?php

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Models\SiteDeployHook;
use Illuminate\Support\Collection;

class DeployHookRunner
{
    public function __construct(
        private readonly DeployPipelineHookExecutor $hookExecutor,
    ) {}

    public function runPhase(RemoteShell $ssh, Site $site, string $phase, string $workingDirectory): string
    {
        $site->loadMissing('deployHooks');
        /** @var Collection<int, SiteDeployHook> $hooks */
        $hooks = $site->deployHooks
            ->where('anchor', $phase)
            ->whereNull('anchor_step_id')
            ->sortBy('sort_order')
            ->values();

        return $this->runHookCollection($hooks, $site, $workingDirectory, $ssh);
    }

    /**
     * @param  Collection<int, SiteDeployHook>  $hooks
     */
    public function runHookCollection(
        Collection $hooks,
        Site $site,
        string $workingDirectory,
        ?RemoteShell $ssh = null,
    ): string {
        $log = '';
        foreach ($hooks as $hook) {
            $log .= $this->hookExecutor->run($hook, $site, $workingDirectory, $ssh);
            if ($hook->hook_kind === SiteDeployHook::KIND_SHELL) {
                $this->assertShellOutputSucceeded($log, $hook->anchor);
            }
        }

        return $log;
    }

    public function runAfterStep(
        RemoteShell $ssh,
        Site $site,
        string $stepId,
        string $workingDirectory,
    ): string {
        $site->loadMissing('deployHooks');
        $hooks = $site->deployHooks
            ->where('anchor', SiteDeployHook::ANCHOR_AFTER_STEP)
            ->where('anchor_step_id', $stepId)
            ->sortBy('sort_order')
            ->values();

        return $this->runHookCollection($hooks, $site, $workingDirectory, $ssh);
    }

    public function assertHooksSucceeded(string $output, string $label): void
    {
        $this->assertShellOutputSucceeded($output, $label);
    }

    public function assertShellOutputSucceeded(string $output, string $label): void
    {
        if (preg_match_all('/DPLY_HOOK_EXIT:(\d+)/', $output, $m)) {
            foreach ($m[1] as $code) {
                if ((int) $code !== 0) {
                    throw new \RuntimeException("Deploy hook failed ({$label}). Check output for non-zero exit.");
                }
            }
        }
    }
}
