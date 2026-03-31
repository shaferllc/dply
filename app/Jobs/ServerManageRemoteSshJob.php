<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerMetricsGuestPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ServerManageRemoteSshJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public function __construct(
        public string $serverId,
        public string $cacheKey,
        public string $taskName,
        public string $inlineBash,
        public int $timeoutSeconds,
        public ?string $flashSuccessMessage = null,
    ) {
        $this->timeout = max(60, $timeoutSeconds + 60);
        $queue = config('server_manage.remote_task_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public static function cacheKey(string $id): string
    {
        return 'server_manage_remote:'.$id;
    }

    /**
     * Latest request id for this server + task; only that job may complete successfully.
     */
    public static function activeRequestCacheKey(string $serverId, string $taskName): string
    {
        return 'server_manage_remote_active:'.$serverId.':'.sha1($taskName);
    }

    public function handle(ServerManageSshExecutor $executor): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            $this->failCache(__('Server not found.'));

            return;
        }

        if ($this->isSuperseded()) {
            $this->mergePayload([
                'status' => 'failed',
                'output' => '',
                'error' => __('This request was replaced by a newer one.'),
                'flash_success' => null,
            ]);

            return;
        }

        $this->mergePayload([
            'status' => 'running',
            'output' => '',
            'error' => null,
        ]);

        $fullOutput = '';
        $lastFlush = microtime(true);
        $lastSupersedeCheck = microtime(true);
        $flushInterval = (float) config('server_manage.remote_task_cache_flush_seconds', 0.5);
        $supersedeCheckInterval = max(0.15, $flushInterval);

        $onOutput = function (string $type, string $buffer) use (&$fullOutput, &$lastFlush, &$lastSupersedeCheck, $flushInterval, $supersedeCheckInterval): void {
            $fullOutput .= $buffer;
            $now = microtime(true);
            if ($now - $lastSupersedeCheck >= $supersedeCheckInterval) {
                $lastSupersedeCheck = $now;
                if ($this->isSuperseded()) {
                    throw ManageRemoteTaskSupersededException::make();
                }
            }
            if ($now - $lastFlush >= $flushInterval || strlen($buffer) > 8192) {
                $lastFlush = $now;
                $this->mergePayload([
                    'output' => ServerManageSshExecutor::stripSshClientNoise($fullOutput),
                ]);
            }
        };

        try {
            $out = $executor->runInlineBash(
                $server,
                $this->taskName,
                $this->inlineBash,
                $this->timeoutSeconds,
                $onOutput,
            );
            $fullOutput = $out->getBuffer();
            $trimmed = trim(ServerManageSshExecutor::stripSshClientNoise($fullOutput));

            if ($this->isSuperseded()) {
                $this->mergePayload([
                    'status' => 'failed',
                    'output' => $trimmed,
                    'error' => __('This request was replaced by a newer one.'),
                    'flash_success' => null,
                ]);

                return;
            }

            if (! $out->isSuccessful()) {
                $this->mergePayload([
                    'status' => 'failed',
                    'output' => $trimmed,
                    'error' => __('Remote command exited with code :code.', ['code' => (string) ($out->getExitCode() ?? 'unknown')]),
                    'flash_success' => null,
                ]);

                return;
            }

            $this->mergePayload([
                'status' => 'finished',
                'output' => $trimmed,
                'error' => null,
                'flash_success' => $this->flashSuccessMessage,
            ]);

            if ($this->taskName === 'services-install:install_monitoring_prerequisites') {
                $server = Server::query()->find($this->serverId);
                if ($server !== null) {
                    app(ServerMetricsGuestPushService::class)->syncPushArtifactsAfterInstall($server);
                }
            }
        } catch (ManageRemoteTaskSupersededException) {
            $trimmed = trim(ServerManageSshExecutor::stripSshClientNoise($fullOutput));
            $this->mergePayload([
                'status' => 'failed',
                'output' => $trimmed,
                'error' => __('This request was replaced by a newer one.'),
                'flash_success' => null,
            ]);
        } catch (Throwable $e) {
            $this->mergePayload([
                'status' => 'failed',
                'output' => trim(ServerManageSshExecutor::stripSshClientNoise($fullOutput)),
                'error' => $e->getMessage(),
                'flash_success' => null,
            ]);
        }
    }

    protected function isSuperseded(): bool
    {
        if (! (bool) config('server_manage.supersede_duplicate_remote_tasks', true)) {
            return false;
        }

        $active = Cache::get(self::activeRequestCacheKey($this->serverId, $this->taskName));

        return is_string($active) && $active !== '' && $active !== $this->cacheKey;
    }

    public function failed(?Throwable $exception): void
    {
        $this->failCache($exception?->getMessage() ?? __('The remote task failed.'));
    }

    protected function failCache(string $message): void
    {
        $existing = Cache::get(self::cacheKey($this->cacheKey), []);
        $this->mergePayload([
            'status' => 'failed',
            'output' => is_array($existing) ? ($existing['output'] ?? '') : '',
            'error' => $message,
            'flash_success' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mergePayload(array $data): void
    {
        $key = self::cacheKey($this->cacheKey);
        $ttlSeconds = (int) config('server_manage.remote_task_cache_ttl_seconds', 900);
        $existing = Cache::get($key, []);
        if (! is_array($existing)) {
            $existing = [];
        }
        Cache::put($key, array_merge($existing, $data), now()->addSeconds(max(60, $ttlSeconds)));
    }
}
