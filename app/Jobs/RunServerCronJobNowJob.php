<?php

namespace App\Jobs;

use App\Events\Servers\ServerCronRunCompletedBroadcast;
use App\Events\Servers\ServerCronRunMetaBroadcast;
use App\Events\Servers\ServerCronRunOutputChunkBroadcast;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerCronJobRun;
use App\Services\Servers\CronJobAlertDispatcher;
use App\Services\Servers\CronJobRunResult;
use App\Services\Servers\ServerCronCommandBuilder;
use App\Services\Servers\ServerCronJobRunner;
use App\Services\Servers\ServerManageSshExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunServerCronJobNowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'cron-run',
            'server:'.$this->serverId,
        ];
    }

    public function __construct(
        public string $serverId,
        public string $cronJobId,
        public string $runId,
    ) {
        $this->timeout = 420;
        $queue = config('server_workspace.cron_run.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public static function cacheKey(string $runId): string
    {
        return 'server_cron_run:'.$runId;
    }

    public function handle(
        ServerCronJobRunner $runner,
        ServerCronCommandBuilder $commandBuilder,
        CronJobAlertDispatcher $alerts,
    ): void {
        $ttl = max(60, (int) config('server_workspace.cron_run.cache_ttl_seconds', 900));
        $chunkMs = max(30, (int) config('server_workspace.cron_run.broadcast_chunk_interval_ms', 120));

        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            $this->failRun(__('Server not found.'), $ttl);

            return;
        }

        $job = ServerCronJob::query()
            ->where('server_id', $this->serverId)
            ->whereKey($this->cronJobId)
            ->first();

        if ($job === null) {
            $this->failRun(__('Cron job not found.'), $ttl);

            return;
        }

        $server = $server->fresh();
        $segment = $commandBuilder->crontabCommandSegment($server, $job);
        $shown = $segment !== '' ? $segment : $job->command;

        if ($job->depends_on_job_id) {
            $depOk = ServerCronJobRun::query()
                ->where('server_cron_job_id', $job->depends_on_job_id)
                ->where('status', ServerCronJobRun::STATUS_FINISHED)
                ->where('exit_code', 0)
                ->exists();
            if (! $depOk) {
                $this->failRun(
                    __('This job depends on another — run the dependency successfully first (see Run history).'),
                    $ttl
                );

                return;
            }
        }

        $metaHtml = '<p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">'.e(__('Run now')).'</p>'
            .'<pre class="mt-2 whitespace-pre-wrap break-all font-mono text-[11px] leading-snug text-brand-ink">'
            .e($shown).'</pre>';

        $this->mergePayload([
            'status' => 'running',
            'meta_html' => $metaHtml,
            'output' => '',
            'error' => null,
            'flash_success' => null,
            'queued_at' => time(),
        ], $ttl);

        broadcast(new ServerCronRunMetaBroadcast($this->serverId, $this->runId, $metaHtml));

        $runRecord = ServerCronJobRun::query()->create([
            'server_cron_job_id' => $job->id,
            'run_ulid' => $this->runId,
            'trigger' => ServerCronJobRun::TRIGGER_QUEUED,
            'status' => ServerCronJobRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $fullOutput = '';
        $lastBroadcast = microtime(true);
        $lastCacheFlush = microtime(true);
        $flushInterval = 0.35;
        $startedAt = microtime(true);

        $onChunk = function (string $chunk) use (&$fullOutput, &$lastBroadcast, &$lastCacheFlush, $chunkMs, $flushInterval, $ttl): void {
            $fullOutput .= $chunk;
            $now = microtime(true);
            if (($now - $lastBroadcast) * 1000 >= $chunkMs && $chunk !== '') {
                $lastBroadcast = $now;
                broadcast(new ServerCronRunOutputChunkBroadcast($this->serverId, $this->runId, $chunk));
            }
            if ($now - $lastCacheFlush >= $flushInterval) {
                $lastCacheFlush = $now;
                $this->mergePayload([
                    'output' => ServerManageSshExecutor::stripSshClientNoise($fullOutput),
                ], $ttl);
            }
        };

        try {
            $result = $runner->runNow($server, $job, 300, $onChunk);
            $trimmed = trim(ServerManageSshExecutor::stripSshClientNoise($result->output));
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->finishRunRecord($runRecord, $result, $trimmed, $durationMs);

            if (! $result->succeeded()) {
                $alerts->dispatchIfNeeded($server, $job, $result);
                $displayOut = $this->humanizeEmptyRunOutput($trimmed, $result->exitCode, $durationMs, false);
                $this->mergePayload([
                    'status' => 'failed',
                    'output' => $displayOut,
                    'error' => __('Command exited with code :code.', ['code' => (string) ($result->exitCode ?? '?')]),
                    'flash_success' => null,
                ], $ttl);

                broadcast(new ServerCronRunOutputChunkBroadcast(
                    $this->serverId,
                    $this->runId,
                    "\n".__('Exit code: :code', ['code' => (string) ($result->exitCode ?? '?')])."\n",
                ));

                broadcast(new ServerCronRunCompletedBroadcast(
                    $this->serverId,
                    $this->runId,
                    false,
                    __('Non-zero exit code.'),
                    null,
                    $displayOut,
                ));

                return;
            }

            $alerts->dispatchIfNeeded($server, $job, $result);

            $displayOut = $this->humanizeEmptyRunOutput($trimmed, $result->exitCode, $durationMs, true);

            $this->mergePayload([
                'status' => 'finished',
                'output' => $displayOut,
                'error' => null,
                'flash_success' => __('Finished. Output is saved under “Last run output”.'),
            ], $ttl);

            broadcast(new ServerCronRunCompletedBroadcast(
                $this->serverId,
                $this->runId,
                true,
                null,
                __('Finished. Output is saved under “Last run output”.'),
                $displayOut,
            ));
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $out = ServerManageSshExecutor::stripSshClientNoise($fullOutput);
            $outTrim = trim($out);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $runRecord->update([
                'status' => ServerCronJobRun::STATUS_FAILED,
                'exit_code' => null,
                'duration_ms' => $durationMs,
                'output' => $outTrim !== '' ? substr($outTrim, 0, 65535) : null,
                'error_message' => $msg,
                'finished_at' => now(),
            ]);

            $displayExc = $outTrim !== '' ? $outTrim : __('Error: :msg', ['msg' => $msg]);

            $this->mergePayload([
                'status' => 'failed',
                'output' => $displayExc,
                'error' => $msg,
                'flash_success' => null,
            ], $ttl);

            broadcast(new ServerCronRunOutputChunkBroadcast(
                $this->serverId,
                $this->runId,
                "\n".__('Error: :msg', ['msg' => $msg])."\n",
            ));

            broadcast(new ServerCronRunCompletedBroadcast(
                $this->serverId,
                $this->runId,
                false,
                $msg,
                null,
                $displayExc,
            ));
        }
    }

    protected function finishRunRecord(
        ServerCronJobRun $runRecord,
        CronJobRunResult $result,
        string $trimmedOutput,
        int $durationMs,
    ): void {
        $runRecord->update([
            'status' => $result->succeeded() ? ServerCronJobRun::STATUS_FINISHED : ServerCronJobRun::STATUS_FAILED,
            'exit_code' => $result->exitCode,
            'duration_ms' => $durationMs,
            'output' => $trimmedOutput !== '' ? substr($trimmedOutput, 0, 65535) : null,
            'error_message' => $result->succeeded() ? null : __('Non-zero exit code.'),
            'finished_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $ttl = max(60, (int) config('server_workspace.cron_run.cache_ttl_seconds', 900));
        $msg = $exception?->getMessage() ?? __('The cron run job failed.');
        $this->mergePayload([
            'status' => 'failed',
            'error' => $msg,
            'flash_success' => null,
        ], $ttl);

        ServerCronJobRun::query()
            ->where('run_ulid', $this->runId)
            ->update([
                'status' => ServerCronJobRun::STATUS_FAILED,
                'error_message' => $msg,
                'finished_at' => now(),
            ]);

        broadcast(new ServerCronRunCompletedBroadcast(
            $this->serverId,
            $this->runId,
            false,
            $msg,
            null,
            null,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * Quiet commands (e.g. apt-get -qq) often produce no stdout; the UI would otherwise show an empty panel after the run ends.
     */
    protected function humanizeEmptyRunOutput(string $trimmed, ?int $exitCode, int $durationMs, bool $success): string
    {
        if ($trimmed !== '') {
            return $trimmed;
        }
        $secs = max(0.1, round($durationMs / 1000, 1));
        if ($success) {
            return __('(No output captured — quiet flags like apt -qq often print nothing until the command exits. Finished in :seconds s, exit :code.)', [
                'seconds' => (string) $secs,
                'code' => (string) ($exitCode ?? 0),
            ]);
        }

        return __('(No output captured. Exit :code, :seconds s.)', [
            'code' => (string) ($exitCode ?? '?'),
            'seconds' => (string) $secs,
        ]);
    }

    protected function mergePayload(array $data, int $ttlSeconds): void
    {
        $key = self::cacheKey($this->runId);
        $existing = Cache::get($key, []);
        if (! is_array($existing)) {
            $existing = [];
        }
        Cache::put($key, array_merge($existing, $data), now()->addSeconds($ttlSeconds));
    }

    protected function failRun(string $message, int $ttl): void
    {
        $this->mergePayload([
            'status' => 'failed',
            'meta_html' => '',
            'output' => '',
            'error' => $message,
            'flash_success' => null,
        ], $ttl);

        broadcast(new ServerCronRunCompletedBroadcast(
            $this->serverId,
            $this->runId,
            false,
            $message,
            null,
            null,
        ));
    }
}
