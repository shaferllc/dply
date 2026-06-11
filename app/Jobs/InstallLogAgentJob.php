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
 * Installs (or re-syncs) the dply Logs edge agent (Vector) on a box over SSH.
 * Sibling to {@see InstallHttpCacheDaemonJob}: flips the row to `installing`,
 * streams install output into the row for the workspace's live progress view,
 * parses the resulting Vector version, and lands on `running` or `failed`.
 *
 * Idempotent: re-dispatching after a config/source/version change re-renders
 * vector.toml + the unit and restarts the service. See docs/SERVER_LOGS_ADDON.md.
 */
class InstallLogAgentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(
        public string $serverLogAgentId,
    ) {
        $queue = config('server_logs.install_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    /** Auto-expire the unique lock so a lost/killed run can't wedge it forever. */
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'install-log-agent:'.$this->serverLogAgentId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        VectorLogAgentInstallScripts $scripts,
    ): void {
        // NOTE: intentionally NOT re-checking config('server_logs.enabled') here.
        // The enable action already gates on it; re-checking inside a long-running
        // queue worker is fragile to config drift (a worker booted before the
        // config existed sees it false and silently no-ops, stranding the row at
        // "installing" forever). Reaching this job means enablement was intended.
        /** @var ServerLogAgent|null $agent */
        $agent = ServerLogAgent::query()->with('server')->find($this->serverLogAgentId);
        if ($agent === null || $agent->server === null || ! $agent->server->isVmHost()) {
            // Can't proceed — don't leave the UI stuck on "installing".
            $agent?->update([
                'status' => ServerLogAgent::STATUS_FAILED,
                'error_message' => 'Server is not a reachable VM host.',
            ]);

            return;
        }

        $agent->update([
            'status' => ServerLogAgent::STATUS_INSTALLING,
            'error_message' => null,
            'install_output' => '',
        ]);

        try {
            $script = $scripts->installScript($agent);

            // Throttle DB writes: the install streams a lot of curl/tar chunks.
            $buffer = '';
            $lastFlush = 0.0;
            $flush = function (bool $force = false) use ($agent, &$buffer, &$lastFlush): void {
                $now = microtime(true);
                if (! $force && ($now - $lastFlush) < 3.0) {
                    return;
                }
                $lastFlush = $now;
                $agent->update(['install_output' => mb_substr($buffer, -32_000)]);
            };

            $output = $executor->runInlineBashWithOutputCallback(
                $agent->server,
                'log-agent:install',
                $script,
                function (string $type, string $chunk) use (&$buffer, $flush): void {
                    $buffer .= $chunk;
                    $flush();
                },
                timeoutSeconds: 600,
                asRoot: true,
            );
            $flush(true);

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Log agent install failed.'
                );
            }

            $agent->update([
                'status' => ServerLogAgent::STATUS_RUNNING,
                'version' => $scripts->parseVersion($output->buffer),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            $agent->update([
                'status' => ServerLogAgent::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 800),
            ]);

            throw $e;
        }
    }
}
