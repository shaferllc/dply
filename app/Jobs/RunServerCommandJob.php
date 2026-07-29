<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerCommandRun;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Worker for {@see ServerCommandRun} rows queued from the Run page.
 *
 * Picks up a queued run, marks it 'running', shells out via the existing
 * TaskRunner SSH layer ({@see ExecuteRemoteTaskOnServer}) as the server's
 * ssh-user — not root — then settles the row to 'completed'/'failed' and
 * writes an {@see AuditLog} entry.
 *
 * Output is flushed to the row incrementally (throttled) so the Run page
 * can stream stdout/stderr live via wire:poll while the command runs.
 */
class RunServerCommandJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Outer wall-clock cap for a single run (15 min). */
    public int $timeout = 900;

    public int $tries = 1;

    /** Flush streamed output to the DB at most this often (seconds). */
    private const FLUSH_INTERVAL = 0.75;

    /** ...or whenever this many unflushed bytes accumulate. */
    private const FLUSH_BYTES = 4096;

    public function __construct(public string $serverCommandRunId)
    {
        $this->onQueue('dply-control');
    }

    public function handle(ExecuteRemoteTaskOnServer $executor): void
    {
        $run = ServerCommandRun::query()->find($this->serverCommandRunId);
        if ($run === null) {
            return;
        }

        // Idempotence — a retried worker shouldn't re-run a settled or
        // already-running row.
        if ($run->status !== ServerCommandRun::STATUS_QUEUED) {
            return;
        }

        $server = $run->server;
        if ($server === null) {
            $run->fill([
                'status' => ServerCommandRun::STATUS_FAILED,
                'stderr' => 'Server no longer exists.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        $run->fill([
            'status' => ServerCommandRun::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();

        $stdout = '';
        $stderr = '';
        $lastFlush = microtime(true);
        $dirtyBytes = 0;

        $flush = function () use ($run, &$stdout, &$stderr, &$lastFlush, &$dirtyBytes): void {
            $run->forceFill([
                'stdout' => $stdout !== '' ? $stdout : null,
                'stderr' => $stderr !== '' ? $stderr : null,
            ])->save();
            $lastFlush = microtime(true);
            $dirtyBytes = 0;
        };

        try {
            $script = "#!/bin/bash\n".$run->command."\n";

            $output = $executor->runScriptWithOutputCallback(
                server: $server,
                name: 'server-run:'.$run->source.':'.$run->id,
                script: $script,
                onOutput: function (string $type, string $chunk) use (&$stdout, &$stderr, &$dirtyBytes, &$lastFlush, $flush): void {
                    if ($type === 'err') {
                        $stderr .= $chunk;
                    } else {
                        $stdout .= $chunk;
                    }
                    $dirtyBytes += strlen($chunk);

                    if ($dirtyBytes >= self::FLUSH_BYTES
                        || (microtime(true) - $lastFlush) >= self::FLUSH_INTERVAL) {
                        $flush();
                    }
                },
                timeoutSeconds: $this->timeout,
            );

            $exitCode = $output->getExitCode();
            $isTimeout = $output->isTimeout();

            $run->fill([
                'status' => $isTimeout || $exitCode !== 0
                    ? ServerCommandRun::STATUS_FAILED
                    : ServerCommandRun::STATUS_COMPLETED,
                'exit_code' => $exitCode,
                'stdout' => $stdout !== '' ? $stdout : null,
                'stderr' => $stderr !== '' ? $stderr : null,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::warning('Server command run threw', [
                'run_id' => $run->id,
                'server_id' => $server->id,
                'source' => $run->source,
                'error' => $e->getMessage(),
            ]);

            $run->fill([
                'status' => ServerCommandRun::STATUS_FAILED,
                'stderr' => trim(($stderr !== '' ? $stderr."\n" : '').$e->getMessage()),
                'stdout' => $stdout !== '' ? $stdout : null,
                'finished_at' => now(),
            ])->save();
        }

        $this->writeAudit($run, $server);
    }

    protected function writeAudit(ServerCommandRun $run, Server $server): void
    {
        AuditLog::create([
            'organization_id' => $server->organization_id,
            'user_id' => $run->queued_by_user_id,
            'action' => 'server.command.run',
            'subject_type' => Server::class,
            'subject_id' => $server->id,
            'new_values' => [
                'source' => $run->source,
                'display_command' => $run->display_command,
                'status' => $run->status,
                'exit_code' => $run->exit_code,
                'container_scope' => $run->container_scope_name,
            ],
        ]);
    }
}
