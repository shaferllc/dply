<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Modules\TaskRunner\ProcessOutput;

/**
 * Runs allowlisted manage inline bash over TaskRunner SSH (root + optional deploy fallback).
 */
class ServerManageSshExecutor
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @param  callable(string, string):void  $onOutput
     */
    public function runInlineBash(
        Server $server,
        string $taskName,
        string $inlineBash,
        ?int $timeoutSeconds,
        callable $onOutput,
    ): ProcessOutput {
        $useRoot = (bool) config('server_manage.use_root_ssh', true);
        $fallback = (bool) config('server_manage.fallback_to_deploy_user_ssh', true);

        if (! $useRoot) {
            return $this->remote->runInlineBashWithOutputCallback(
                $server,
                $taskName,
                $inlineBash,
                $onOutput,
                $timeoutSeconds,
                false,
            );
        }

        try {
            return $this->remote->runInlineBashWithOutputCallback(
                $server,
                $taskName,
                $inlineBash,
                $onOutput,
                $timeoutSeconds,
                true,
            );
        } catch (\Throwable $e) {
            if (! $fallback) {
                throw $e;
            }

            $onOutput('out', "\n\n--- ".__('Retrying as deploy SSH user')." ---\n\n");

            return $this->remote->runInlineBashWithOutputCallback(
                $server,
                $taskName,
                $inlineBash,
                $onOutput,
                $timeoutSeconds,
                false,
            );
        }
    }

    public static function stripSshClientNoise(string $buffer): string
    {
        $cleaned = preg_replace('/^Warning: Permanently added[^\r\n]*\R?/m', '', $buffer);

        return is_string($cleaned) ? $cleaned : $buffer;
    }
}
