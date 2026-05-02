<?php

namespace App\Livewire\Servers;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsServerInventoryProbe;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceManage extends Component
{
    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use RunsServerInventoryProbe;

    /** @var string Manage sub-page slug (see config server_manage.workspace_tabs). */
    public string $section = 'overview';

    public string $manage_db_bind_host = '';

    public ?int $manage_db_port = null;

    public string $manage_db_password = '';

    public string $manage_auto_updates_interval = 'off';

    public ?string $remote_output = null;

    public ?string $remote_error = null;

    /**
     * When set, {@see syncManageRemoteTaskFromCache} polls cache until the queued SSH task finishes.
     */
    public ?string $manageRemoteTaskId = null;

    public function mount(Server $server, ?string $section = null): void
    {
        if ($section === null) {
            $this->redirect(route('servers.manage', ['server' => $server, 'section' => 'overview']), navigate: true);

            return;
        }

        $allowed = array_keys(config('server_manage.workspace_tabs', []));
        if (! in_array($section, $allowed, true)) {
            abort(404);
        }

        $this->section = $section;

        $this->bootWorkspace($server);
        $meta = $server->meta ?? [];
        $this->manage_db_bind_host = (string) ($meta['manage_db_bind_host'] ?? '');
        $port = $meta['manage_db_port'] ?? null;
        $this->manage_db_port = is_numeric($port) ? (int) $port : null;
        $this->manage_auto_updates_interval = (string) ($meta['manage_auto_updates_interval'] ?? 'off');
    }

    public function saveManageMetadata(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server manage settings.'));

            return;
        }

        $this->validate([
            'manage_db_bind_host' => ['nullable', 'string', 'max:255'],
            'manage_db_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'manage_auto_updates_interval' => ['required', 'string', 'in:'.implode(',', array_keys(config('server_manage.auto_update_intervals', [])))],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['manage_db_bind_host'] = $this->manage_db_bind_host !== '' ? $this->manage_db_bind_host : null;
        $meta['manage_db_port'] = $this->manage_db_port;
        $meta['manage_auto_updates_interval'] = $this->manage_auto_updates_interval;

        if ($this->manage_db_password !== '') {
            $meta['manage_internal_db_password'] = $this->manage_db_password;
        }

        $this->server->update(['meta' => $meta]);
        $this->manage_db_password = '';
        $this->server->refresh();
        $this->toastSuccess(__('Manage preferences saved.'));
    }

    public function previewConfig(string $key): void
    {
        $previews = config('server_manage.config_previews', []);
        $entry = $previews[$key] ?? null;
        if (! is_array($entry) || empty($entry['path'])) {
            $this->remote_error = __('Unknown configuration preview.');

            return;
        }

        $this->runConfigPreview('manage-config-preview:'.$key, (string) $entry['path']);
    }

    public function previewConfigPath(string $path): void
    {
        $taskName = 'manage-config-preview-path:'.substr(sha1($path), 0, 12);
        $this->runConfigPreview($taskName, $path);
    }

    protected function runConfigPreview(string $taskName, string $path): void
    {
        $this->authorize('update', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;

        if ($this->currentUserIsDeployer()) {
            $this->remote_error = __('Deployers cannot read configuration over SSH.');

            return;
        }

        try {
            $this->assertAllowlistedConfigPath($path);
        } catch (\InvalidArgumentException) {
            $this->remote_error = __('That path is not allowlisted.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('SSH must be ready before previewing configuration.');

            return;
        }

        set_time_limit(120);

        $max = (int) config('server_manage.config_preview_max_bytes', 48_000);
        $pathArg = escapeshellarg($path);
        $inline = <<<BASH
path={$pathArg}
max={$max}
if [[ -r "\$path" ]]; then
  head -c "\$max" "\$path" || true
else
  echo "Not found or not readable: \$path"
fi
BASH;

        try {
            $server = $this->server->fresh();
            $title = __('TaskRunner (SSH)').' — '.__('Configuration preview').': '.$path;
            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedManageScript($server, $taskName, $inline, 120, null, $title);

                return;
            }

            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta($title, $this->manageSshConnectionLabel($server)."\n".__('Remote script').":\n".$inline);
            $out = $this->runManageInlineBash(
                $server,
                $taskName,
                $inline,
                fn (string $type, string $buffer) => $this->remoteSshStreamAppendStdout($buffer),
                120,
            );
            $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
        } catch (\Throwable $e) {
            $this->remote_error = $e->getMessage();
        }
    }

    public function cancelQueuedManageTasks(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot cancel queued tasks.'));

            return;
        }

        $cleared = 0;
        if ($this->manageRemoteTaskId !== null && $this->manageRemoteTaskId !== '') {
            Cache::forget(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
            $cleared++;
        }
        $this->manageRemoteTaskId = null;
        $this->remote_output = null;
        $this->remote_error = null;
        $this->resetRemoteSshStreamTargets();
        $this->toastSuccess(__('Cleared :n queued task(s) from this view.', ['n' => $cleared]));
    }

    public function runAllowlistedAction(string $key): void
    {
        $this->authorize('update', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;

        if ($this->currentUserIsDeployer()) {
            $this->remote_error = __('Deployers cannot run service actions on servers.');

            return;
        }

        $service = config('server_manage.service_actions', []);
        $danger = config('server_manage.dangerous_actions', []);
        $def = $service[$key] ?? $danger[$key] ?? null;
        if (! is_array($def) || empty($def['script'])) {
            $this->remote_error = __('Unknown action.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('Provisioning and SSH must be ready before running actions.');

            return;
        }

        set_time_limit((int) ($def['timeout'] ?? 120) + 30);

        $script = (string) $def['script'];
        $meta = $this->server->meta ?? [];
        if (in_array($key, ['restart_php_fpm', 'reload_php_fpm'], true)) {
            $v = (string) ($meta['default_php_version'] ?? '8.3');
            if (! preg_match('/^\d+\.\d+$/', $v)) {
                $v = '8.3';
            }
            $script = 'export DPLY_PHP_VERSION='.escapeshellarg($v)."\n".$script;
        }
        if (str_starts_with($key, 'mysql_') && ! empty($meta['manage_internal_db_password']) && is_string($meta['manage_internal_db_password'])) {
            $script = 'export DPLY_DB_PASSWORD='.escapeshellarg($meta['manage_internal_db_password'])."\n".$script;
        }

        try {
            $server = $this->server->fresh();
            $timeout = isset($def['timeout']) ? (int) $def['timeout'] : null;
            $flash = ($def['label'] ?? $key).' '.__('finished.');

            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedManageScript(
                    $server,
                    'manage-action:'.$key,
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
                'manage-action:'.$key,
                $script,
                fn (string $type, string $buffer) => $this->remoteSshStreamAppendStdout($buffer),
                $timeout,
            );
            $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->toastSuccess($flash);
        } catch (\Throwable $e) {
            $this->remote_error = $e->getMessage();
        }
    }

    public function syncManageRemoteTaskFromCache(): void
    {
        if ($this->manageRemoteTaskId === null || $this->manageRemoteTaskId === '') {
            return;
        }

        $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
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
                    ? __('Task still queued. Ensure a queue worker is running (e.g. php artisan queue:work) and that CACHE_DRIVER is shared with the worker (not "array").')
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
        } else {
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
        $this->manageRemoteTaskId = null;
    }

    public function render(): View
    {
        $this->server->refresh();

        $recentActions = $this->section === 'overview'
            ? ServerManageAction::query()
                ->where('server_id', $this->server->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
            : collect();

        return view('livewire.servers.workspace-manage', [
            'configPreviews' => config('server_manage.config_previews', []),
            'serviceActions' => config('server_manage.service_actions', []),
            'dangerousActions' => config('server_manage.dangerous_actions', []),
            'autoUpdateIntervals' => config('server_manage.auto_update_intervals', []),
            'recentActions' => $recentActions,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }

    protected function assertAllowlistedConfigPath(string $path): void
    {
        $normalized = str_starts_with($path, '/') ? $path : '/'.$path;
        if (str_contains($normalized, '..')) {
            throw new \InvalidArgumentException;
        }

        foreach (config('server_manage.allowed_config_paths_exact', []) as $exact) {
            if ($normalized === $exact) {
                return;
            }
        }

        foreach (config('server_manage.allowed_config_path_prefixes', []) as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return;
            }
        }

        throw new \InvalidArgumentException;
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

    protected function dispatchQueuedManageScript(
        Server $server,
        string $taskName,
        string $inlineBash,
        ?int $timeoutSeconds,
        ?string $flashSuccess,
        string $streamTitle,
    ): void {
        $this->manageRemoteTaskId = null;

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

        // Persist a recent-activity row so Overview can show what's happened.
        $logId = $this->logManageActionStart($server, $taskName, $streamTitle);

        ServerManageRemoteSshJob::dispatch(
            $server->id,
            $id,
            $taskName,
            $inlineBash,
            $timeoutSeconds ?? (int) config('task-runner.default_timeout', 60),
            $flashSuccess,
            $logId,
        );

        $this->manageRemoteTaskId = $id;
        $this->remote_output = __('Task queued. This page will update when the server responds.');
        $this->remote_error = null;
        $this->resetRemoteSshStreamTargets();
        $this->remoteSshStreamSetMeta(
            $streamTitle,
            $this->manageSshConnectionLabel($server)."\n".__('Remote script').":\n".$inlineBash."\n\n"
            .__('Runs in a queue worker so the browser request returns immediately. Use a non-sync queue and run `php artisan queue:work`.')
        );
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

    /** Manage tabs always want the extended snapshot regardless of the user's inventory depth setting. */
    protected function forceExtendedInventoryProbe(): bool
    {
        return true;
    }

    /**
     * Persist a recent-activity row so Overview can show "what just happened".
     * Returns the row id so the queued job can update status as it progresses.
     */
    protected function logManageActionStart(Server $server, string $taskName, string $streamTitle): string
    {
        $serviceActions = config('server_manage.service_actions', []);
        $dangerous = config('server_manage.dangerous_actions', []);

        // Best-effort label: try the action key after the colon (e.g. "manage-action:reload_nginx").
        $label = $streamTitle;
        if (preg_match('/^manage-action:(.+)$/', $taskName, $m)) {
            $key = $m[1];
            $label = (string) ($serviceActions[$key]['label'] ?? $dangerous[$key]['label'] ?? $key);
        } elseif (str_starts_with($taskName, 'manage-config-preview')) {
            $label = __('Configuration preview');
        }

        $row = ServerManageAction::create([
            'server_id' => $server->id,
            'user_id' => auth()->id(),
            'task_name' => $taskName,
            'label' => $label,
            'status' => ServerManageAction::STATUS_QUEUED,
        ]);

        return $row->id;
    }
}
