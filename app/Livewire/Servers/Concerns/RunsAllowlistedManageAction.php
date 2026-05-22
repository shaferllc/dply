<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Servers\WorkspaceManage;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerManageAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Dispatch an allowlisted manage action (an entry in
 * config('server_manage.service_actions') or config('server_manage.dangerous_actions'))
 * against the current server, banner-only flow.
 *
 * Equivalent to the queued path of {@see WorkspaceManage::runAllowlistedAction()},
 * trimmed of the legacy "Command output" panel and SSH-stream overlay. The seeded
 * `manage_action` ConsoleAction row drives the page-top console-action banner partial
 * on whichever workspace owns the button (Databases for `mysql_*`, Caches for `redis_info`).
 *
 * The host component must also use {@see InteractsWithServerWorkspace} (provides
 * `serverOpsReady()`, `currentUserIsDeployer()`, `toastSuccess/Error()`, and the
 * `$server` model) and is expected to render the console-action banner partial for
 * the `manage_action` kind so output streams into the UI.
 */
trait RunsAllowlistedManageAction
{
    /** Polling target while a queued manage task is in flight. */
    public ?string $manageRemoteTaskId = null;

    public function runAllowlistedManageAction(string $key): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot run service actions on servers.'));

            return;
        }

        $service = config('server_manage.service_actions', []);
        $danger = config('server_manage.dangerous_actions', []);
        $def = $service[$key] ?? $danger[$key] ?? null;
        if (! is_array($def) || empty($def['script'])) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before running actions.'));

            return;
        }

        $script = (string) $def['script'];
        $meta = $this->server->meta ?? [];

        // PHP-FPM service actions need to know which apt-managed php version's unit
        // to act on; the script reads $DPLY_PHP_VERSION. Mirrors WorkspaceManage.
        if (in_array($key, ['restart_php_fpm', 'reload_php_fpm'], true)) {
            $v = (string) ($meta['default_php_version'] ?? '8.3');
            if (! preg_match('/^\d+\.\d+$/', $v)) {
                $v = '8.3';
            }
            $script = 'export DPLY_PHP_VERSION='.escapeshellarg($v)."\n".$script;
        }

        // MySQL actions pick up the manage_internal_db_password (saved on the
        // Databases workspace's MySQL → Info subtab) so `mysql -uroot -p...` can
        // authenticate without prompting. Mirrors WorkspaceManage.
        if (str_starts_with($key, 'mysql_') && ! empty($meta['manage_internal_db_password']) && is_string($meta['manage_internal_db_password'])) {
            $script = 'export DPLY_DB_PASSWORD='.escapeshellarg($meta['manage_internal_db_password'])."\n".$script;
        }

        $label = (string) ($def['label'] ?? $key);
        $timeout = isset($def['timeout']) ? (int) $def['timeout'] : null;
        $flash = $label.' '.__('finished.');

        $this->queueManageActionScript(
            $this->server->fresh() ?? $this->server,
            'manage-action:'.$key,
            $script,
            $timeout,
            $flash,
            $label,
        );
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
        if (! in_array($status, ['finished', 'failed'], true)) {
            return;
        }

        if ($status === 'finished') {
            $flash = $payload['flash_success'] ?? null;
            if (is_string($flash) && $flash !== '') {
                $this->toastSuccess($flash);
            }
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
        $this->manageRemoteTaskId = null;
    }

    protected function queueManageActionScript(
        Server $server,
        string $taskName,
        string $script,
        ?int $timeoutSeconds,
        ?string $flashSuccess,
        ?string $consoleLabel,
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

        $logId = $this->logManageActionRowStart($server, $taskName, $consoleLabel);
        $consoleId = $this->seedManageActionConsoleRow($server, $consoleLabel ?? $taskName);

        ServerManageRemoteSshJob::dispatch(
            $server->id,
            $id,
            $taskName,
            $script,
            $timeoutSeconds ?? (int) config('task-runner.default_timeout', 60),
            $flashSuccess,
            $logId,
            null,
            $consoleId,
        );

        $this->manageRemoteTaskId = $id;
    }

    protected function logManageActionRowStart(Server $server, string $taskName, ?string $label): string
    {
        $serviceActions = config('server_manage.service_actions', []);
        $dangerous = config('server_manage.dangerous_actions', []);

        $resolved = $label;
        if (preg_match('/^manage-action:(.+)$/', $taskName, $m)) {
            $key = $m[1];
            $resolved = (string) ($serviceActions[$key]['label'] ?? $dangerous[$key]['label'] ?? $key);
        }

        $row = ServerManageAction::create([
            'server_id' => $server->id,
            'user_id' => auth()->id(),
            'task_name' => $taskName,
            'label' => $resolved ?? $taskName,
            'status' => ServerManageAction::STATUS_QUEUED,
        ]);

        return $row->id;
    }

    protected function seedManageActionConsoleRow(Server $server, string $label): string
    {
        ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        $row = ConsoleAction::query()->create([
            'subject_type' => $server->getMorphClass(),
            'subject_id' => $server->id,
            'kind' => 'manage_action',
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => auth()->id(),
            'label' => $label.' …',
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        return (string) $row->id;
    }
}
