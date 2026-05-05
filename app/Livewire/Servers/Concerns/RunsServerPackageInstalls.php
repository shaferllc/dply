<?php

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Models\Server;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerMetricsGuestPushService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Allowlisted apt installs from {@see config('server_services.install_actions')}.
 *
 * @phpstan-require-extends Component
 */
trait RunsServerPackageInstalls
{
    use StreamsRemoteSshLivewire;

    public ?string $remote_output = null;

    public ?string $remote_error = null;

    public ?string $servicesRemoteTaskId = null;

    public function runInstallAction(string $key): void
    {
        $this->authorize('update', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;

        if ($this->currentUserIsDeployer()) {
            $this->remote_error = __('Deployers cannot install packages on servers.');

            return;
        }

        $def = config('server_services.install_actions', [])[$key] ?? null;
        if (! is_array($def) || empty($def['script'])) {
            $this->remote_error = __('Unknown install action.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('Provisioning and SSH must be ready before installing packages.');

            return;
        }

        set_time_limit((int) ($def['timeout'] ?? 600) + 30);

        $script = (string) $def['script'];

        try {
            $server = $this->server->fresh();
            $timeout = isset($def['timeout']) ? (int) $def['timeout'] : null;
            $flash = ($def['label'] ?? $key).' '.__('finished.');

            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedServicesScript(
                    $server,
                    'services-install:'.$key,
                    $script,
                    $timeout,
                    $flash,
                    __('TaskRunner (SSH)').' — '.($def['label'] ?? $key),
                );

                return;
            }

            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta(
                __('TaskRunner (SSH)').' — '.($def['label'] ?? $key),
                $this->manageSshConnectionLabel($server)."\n".__('Remote script').":\n".$script
            );
            $out = $this->runManageInlineBash(
                $server,
                'services-install:'.$key,
                $script,
                fn (string $type, string $buffer) => $this->remoteSshStreamAppendStdout($buffer),
                $timeout,
            );
            $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->toastSuccess($flash);
            if ($key === 'install_monitoring_prerequisites') {
                app(ServerMetricsGuestPushService::class)->syncPushArtifactsAfterInstall($server);
            }
            $this->dispatch('monitoring-probe-requested');
        } catch (\Throwable $e) {
            $this->remote_error = $e->getMessage();
        }
    }

    public function syncServicesRemoteTaskFromCache(): void
    {
        if ($this->servicesRemoteTaskId === null || $this->servicesRemoteTaskId === '') {
            return;
        }

        $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->servicesRemoteTaskId));
        if (! is_array($payload)) {
            return;
        }

        $status = (string) ($payload['status'] ?? '');
        $out = (string) ($payload['output'] ?? '');
        $queuedAt = isset($payload['queued_at']) && is_numeric($payload['queued_at'])
            ? (int) $payload['queued_at']
            : null;
        $stalledAfter = (int) config('server_manage.remote_task_stalled_queued_seconds', 45);
        $stalledQueued = $status === 'queued'
            && $queuedAt !== null
            && (time() - $queuedAt) > $stalledAfter;

        $this->remote_output = $out !== ''
            ? $out
            : match ($status) {
                'queued' => $stalledQueued
                    ? __('Still preparing this task. If it stays stuck, contact your administrator.')
                    : __('Task queued…'),
                'running' => __('Running on server…'),
                default => '',
            };

        $err = $payload['error'] ?? null;
        $this->remote_error = is_string($err) && $err !== '' ? $err : null;

        if (! in_array($status, ['finished', 'failed'], true)) {
            return;
        }

        if ($status === 'finished') {
            $flash = $payload['flash_success'] ?? null;
            if (is_string($flash) && $flash !== '') {
                $this->toastSuccess($flash);
            }
            $this->dispatch('monitoring-probe-requested');
        } else {
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->servicesRemoteTaskId));
        $this->servicesRemoteTaskId = null;
    }

    /**
     * @param  callable(string, string):void  $onOutput
     */
    protected function runManageInlineBash(
        Server $server,
        string $taskName,
        string $inlineBash,
        callable $onOutput,
        ?int $timeoutSeconds,
    ): ProcessOutput {
        return app(ServerManageSshExecutor::class)->runInlineBash(
            $server,
            $taskName,
            $inlineBash,
            $timeoutSeconds,
            $onOutput,
        );
    }

    protected function shouldQueueManageRemoteTasks(): bool
    {
        return (bool) config('server_manage.queue_remote_tasks', true);
    }

    protected function dispatchQueuedServicesScript(
        Server $server,
        string $taskName,
        string $inlineBash,
        ?int $timeoutSeconds,
        ?string $flashSuccess,
        string $streamTitle,
    ): void {
        $this->servicesRemoteTaskId = null;

        $id = (string) Str::uuid();
        $ttl = (int) config('server_manage.remote_task_cache_ttl_seconds', 900);

        Cache::put(ServerManageRemoteSshJob::cacheKey($id), [
            'status' => 'queued',
            'output' => '',
            'error' => null,
            'flash_success' => null,
            'queued_at' => time(),
        ], now()->addSeconds(max(120, $ttl)));

        if (config('server_manage.supersede_duplicate_remote_tasks', true)) {
            Cache::put(
                ServerManageRemoteSshJob::activeRequestCacheKey($server->id, $taskName),
                $id,
                now()->addSeconds(max(120, $ttl)),
            );
        }

        // Persist a recent-activity row so install progress survives a
        // page reload — the cache-only state vanishes if the operator
        // navigates away. The job updates this row through its lifecycle
        // (queued → running → finished/failed) via updateLog().
        $label = $this->guessInstallActionLabel($taskName) ?? $streamTitle;
        $logRow = \App\Models\ServerManageAction::create([
            'server_id' => $server->id,
            'user_id' => auth()->id(),
            'task_name' => $taskName,
            'label' => $label,
            'status' => \App\Models\ServerManageAction::STATUS_QUEUED,
        ]);

        ServerManageRemoteSshJob::dispatch(
            $server->id,
            $id,
            $taskName,
            $inlineBash,
            $timeoutSeconds ?? (int) config('task-runner.default_timeout', 60),
            $flashSuccess,
            $logRow->id,
        );

        $this->servicesRemoteTaskId = $id;
        $this->remote_output = __('Task queued. This page will update when the server responds.');
        $this->remote_error = null;
        $this->resetRemoteSshStreamTargets();
        $this->remoteSshStreamSetMeta(
            $streamTitle,
            $this->manageSshConnectionLabel($server)."\n".__('Remote script').":\n".$inlineBash."\n\n"
            .__('Runs in the background so the browser does not block on SSH.')
        );
    }

    /**
     * Best-effort human label for an install/services-* task — used in
     * the activity log row so Overview / Services panels show
     * "Install Redis" rather than "services-install:install_redis".
     */
    protected function guessInstallActionLabel(string $taskName): ?string
    {
        if (! preg_match('/^services-install:(.+)$/', $taskName, $m)) {
            return null;
        }
        $key = $m[1];
        $def = config('server_services.install_actions', [])[$key] ?? null;

        return is_array($def) && isset($def['label']) ? (string) $def['label'] : null;
    }

    protected function manageSshConnectionLabel(Server $server): string
    {
        $host = (string) $server->ip_address;
        $deploy = trim((string) $server->ssh_user) ?: 'root';
        if (! (bool) config('server_manage.use_root_ssh', true)) {
            return $deploy.'@'.$host;
        }

        if ($deploy === 'root') {
            return 'root@'.$host;
        }

        return 'root@'.$host.' ('.__('falls back to').' '.$deploy.'@'.$host.')';
    }
}
