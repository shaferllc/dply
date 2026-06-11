<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerLogAgent;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\VectorLogAgentInstallScripts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Tears down the dply Logs edge agent: stops + disables the unit and removes the
 * binary, config, and state from the box. On success the {@see ServerLogAgent}
 * row is deleted (the add-on is no longer present on the server); on failure the
 * row lands on `failed` with the error so the operator can retry.
 *
 * Idempotent on the box — re-running against an already-clean host is a no-op.
 */
class UninstallLogAgentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(
        public string $serverLogAgentId,
    ) {
        $queue = config('server_logs.install_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    /** Auto-expire the unique lock so a lost/killed run can't wedge it forever. */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'uninstall-log-agent:'.$this->serverLogAgentId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        VectorLogAgentInstallScripts $scripts,
    ): void {
        /** @var ServerLogAgent|null $agent */
        $agent = ServerLogAgent::query()->with('server')->find($this->serverLogAgentId);
        if ($agent === null) {
            return;
        }

        // No box to reach (deleted/non-VM) — just drop the row.
        if ($agent->server === null || ! $agent->server->isVmHost()) {
            $agent->delete();

            return;
        }

        $agent->update([
            'status' => ServerLogAgent::STATUS_UNINSTALLING,
            'error_message' => null,
        ]);

        try {
            $output = $executor->runInlineBash(
                $agent->server,
                'log-agent:uninstall',
                $scripts->uninstallScript(),
                120,
                true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Log agent uninstall failed.'
                );
            }

            $agent->delete();
        } catch (\Throwable $e) {
            $agent->update([
                'status' => ServerLogAgent::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 800),
            ]);

            throw $e;
        }
    }
}
