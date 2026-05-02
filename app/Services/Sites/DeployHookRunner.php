<?php

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Models\SiteDeployHook;
use Illuminate\Support\Collection;

class DeployHookRunner
{
    public function runPhase(RemoteShell $ssh, Site $site, string $phase, string $workingDirectory): string
    {
        $site->loadMissing('deployHooks');
        /** @var Collection<int, SiteDeployHook> $hooks */
        $hooks = $site->deployHooks->where('phase', $phase)->sortBy('sort_order')->values();
        $log = '';
        foreach ($hooks as $hook) {
            $script = trim((string) $hook->script);
            if ($script === '') {
                continue;
            }
            $script = app(DeployHookScriptExpander::class)->expand($script, $site);
            $default = (int) config('dply.default_deploy_hook_timeout_seconds', 900);
            $timeout = max(30, min(3600, (int) ($hook->timeout_seconds ?? $default)));
            $log .= "\n--- hook {$phase} #{$hook->id} ---\n";
            $log .= $this->runScript($ssh, $workingDirectory, $script, $timeout);
        }

        return $log;
    }

    protected function runScript(RemoteShell $ssh, string $cwd, string $script, int $timeoutSeconds): string
    {
        $b64 = base64_encode($script);

        return $ssh->exec(
            sprintf(
                'cd %s && echo %s | base64 -d | /usr/bin/env bash 2>&1; printf "\\nDPLY_HOOK_EXIT:%%s" "$?"',
                escapeshellarg($cwd),
                escapeshellarg($b64)
            ),
            $timeoutSeconds
        );
    }

    public function assertHooksSucceeded(string $output, string $label): void
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
