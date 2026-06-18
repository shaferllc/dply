<?php

declare(strict_types=1);

namespace App\Modules\RemoteCli\Jobs;

use App\Models\RemoteCliRun;
use App\Models\SiteAuditEvent;
use App\Modules\RemoteCli\Services\Artisan;
use App\Modules\RemoteCli\Services\Kind;
use App\Modules\RemoteCli\Services\RemoteCli;
use App\Modules\RemoteCli\Services\SiteAuditWriter;
use App\Modules\RemoteCli\Services\WpCli;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Worker for async {@see RemoteCliRun} rows.
 *
 * Picks up a queued run, marks it 'running', shells out via the
 * existing TaskRunner SSH layer with a per-chunk callback that splits
 * stdout / stderr into separate columns, then settles the row to
 * 'completed' or 'failed' and writes a {@see SiteAuditEvent}.
 *
 * Dispatched from {@see RemoteCli::run()} when the requested command
 * isn't on the kind's INSTANT allowlist.
 */
class RunRemoteCliInBackgroundJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Outer wall-clock cap for any single async invocation (15 min). */
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $remoteCliRunId) {}

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        SiteAuditWriter $audit,
    ): void {
        $run = RemoteCliRun::query()->find($this->remoteCliRunId);
        if ($run === null) {
            return;
        }

        // Idempotence — a worker that gets retried after partial state
        // shouldn't re-run an already-running or already-settled row.
        if ($run->status !== RemoteCliRun::STATUS_QUEUED) {
            return;
        }

        $site = $run->site;
        if ($site->server === null) {
            $run->fill([
                'status' => RemoteCliRun::STATUS_FAILED,
                'stderr' => 'Site or server no longer exists.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        $cli = $this->resolveCli($run->kind, $executor);
        $shellCommand = $cli->buildShellForRun($site, $run->command, $run->args ?? []);

        $run->fill([
            'status' => RemoteCliRun::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();

        $stdout = '';
        $stderr = '';

        try {
            $output = $executor->runInlineBashWithOutputCallback(
                server: $site->server,
                name: sprintf('remote-cli:%s:%s', $run->kind->value, $run->command),
                inlineBash: $shellCommand,
                onOutput: function (string $type, string $chunk) use (&$stdout, &$stderr): void {
                    // Symfony Process emits 'err' for stderr; everything
                    // else (typically 'out') lands in stdout.
                    if ($type === 'err') {
                        $stderr .= $chunk;
                    } else {
                        $stdout .= $chunk;
                    }
                },
                timeoutSeconds: $this->timeout,
            );

            $exitCode = $output->getExitCode();
            $isTimeout = $output->isTimeout();

            $run->fill([
                'status' => $isTimeout || $exitCode !== 0
                    ? RemoteCliRun::STATUS_FAILED
                    : RemoteCliRun::STATUS_COMPLETED,
                'exit_code' => $exitCode,
                'stdout' => $stdout !== '' ? $stdout : null,
                'stderr' => $stderr !== '' ? $stderr : null,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::warning('RemoteCli async execution threw', [
                'run_id' => $run->id,
                'site_id' => $site->getKey(),
                'kind' => $run->kind->value,
                'command' => $run->command,
                'error' => $e->getMessage(),
            ]);

            $run->fill([
                'status' => RemoteCliRun::STATUS_FAILED,
                'stderr' => trim(($stderr !== '' ? $stderr."\n" : '').$e->getMessage()),
                'stdout' => $stdout !== '' ? $stdout : null,
                'finished_at' => now(),
            ])->save();
        }

        $audit->record(
            site: $site,
            user: $run->queuedByUser,
            action: $run->kind === Kind::Wp ? 'wp_cli_run' : 'artisan_run',
            risk: $run->risk,
            transport: SiteAuditEvent::TRANSPORT_WEB,
            summary: sprintf(
                '%s %s',
                $run->kind->label(),
                $run->command,
            ),
            payload: [
                'command' => $run->command,
                'args' => $run->args,
                'exit_code' => $run->exit_code,
            ],
            resultStatus: $run->status === RemoteCliRun::STATUS_COMPLETED
                ? SiteAuditEvent::RESULT_SUCCESS
                : SiteAuditEvent::RESULT_FAILURE,
        );
    }

    private function resolveCli(Kind $kind, ExecuteRemoteTaskOnServer $executor): RemoteCli
    {
        return match ($kind) {
            Kind::Wp => app(WpCli::class),
            Kind::Artisan => app(Artisan::class),
        };
    }
}
