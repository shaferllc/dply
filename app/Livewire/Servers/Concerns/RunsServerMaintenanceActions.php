<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ServerManageRemoteSshJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Services\Servers\ServerAptLockBash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Run a curated subset of {@see config('server_manage.service_actions')} /
 * .dangerous_actions on the Maintenance workspace — OS package upkeep, cleanup
 * prunes, and reboot. Reuses the exact Manage-page plumbing: the same queued
 * {@see ServerManageRemoteSshJob}, the same {@see ServerManageAction} activity
 * stream, and the same cache-key/supersession helpers. We only constrain which
 * keys may run (the {@see config('server_maintenance.operations')} allowlist)
 * and label the task `manage-action:{key}` so RecentActionsLog resolves the
 * human label from server_manage config exactly like WorkspaceManage does.
 *
 * Live output renders through the SAME shared {@see ConsoleAction} banner
 * (`console-action-banner-static`) that every other workspace operation uses —
 * no per-page output box. Each run seeds a {@see ConsoleAction} (kind
 * {@see self::OP_CONSOLE_KIND}) and the job mirrors its progress into that row
 * via {@see \App\Services\ConsoleActions\ConsoleEmitter}. The host component
 * must compose {@see RunsServerConsoleActions} for {@see seedConsoleActionRun()}.
 *
 * @phpstan-require-extends Component
 *
 * @property Server $server
 */
trait RunsServerMaintenanceActions
{
    /** ConsoleAction kind for host-upkeep ops streamed into the console drawer. */
    public const OP_CONSOLE_KIND = 'server_maintenance_op';

    /**
     * Flat allowlist of action keys permitted on the Maintenance page.
     *
     * @return list<string>
     */
    protected function maintenanceActionKeys(): array
    {
        $groups = config('server_maintenance.operations', []);

        return collect(is_array($groups) ? $groups : [])
            ->flatten()
            ->map(fn ($key): string => (string) $key)
            ->values()
            ->all();
    }

    /**
     * Resolve a server_manage action definition, honoring the same
     * service-then-dangerous precedence as WorkspaceManage.
     *
     * @return array<string, mixed>|null
     */
    protected function maintenanceActionDef(string $key): ?array
    {
        $service = config('server_manage.service_actions', []);
        $danger = config('server_manage.dangerous_actions', []);
        $def = $service[$key] ?? $danger[$key] ?? null;

        return is_array($def) && ! empty($def['script']) ? $def : null;
    }

    public function runMaintenanceAction(string $key): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot run maintenance operations on servers.'));

            return;
        }

        if (! in_array($key, $this->maintenanceActionKeys(), true)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $def = $this->maintenanceActionDef($key);
        if ($def === null) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before running maintenance operations.'));

            return;
        }

        $timeout = isset($def['timeout']) ? (int) $def['timeout'] : null;
        $label = (string) ($def['label'] ?? $key);
        set_time_limit(($timeout ?? 120) + 30);

        $server = $this->server->fresh() ?? $this->server;
        $script = ServerAptLockBash::wrapManageScript((string) $def['script']);

        $this->dispatchQueuedMaintenanceScript(
            $server,
            'manage-action:'.$key,
            $script,
            $timeout,
            $label.' '.__('finished.'),
            $label,
        );
    }

    /**
     * True while a host-upkeep op for this server is queued or running. Drives
     * the Run-button disabled state (and its refresh poll) now that live output
     * lives in the console drawer rather than an inline banner. Read off the
     * mirrored {@see ConsoleAction} row so it survives a page reload.
     */
    public function maintenanceOpBusy(): bool
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', self::OP_CONSOLE_KIND)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->exists();
    }

    protected function dispatchQueuedMaintenanceScript(
        Server $server,
        string $taskName,
        string $inlineBash,
        ?int $timeoutSeconds,
        ?string $flashSuccess,
        string $label,
    ): void {
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

        // Persist a recent-activity row so progress survives a page reload and
        // shares the Manage page's activity stream. The job advances this row
        // through queued → running → finished/failed via updateLog().
        $logRow = ServerManageAction::create([
            'server_id' => $server->id,
            'user_id' => auth()->id(),
            'task_name' => $taskName,
            'label' => $label,
            'status' => ServerManageAction::STATUS_QUEUED,
        ]);

        // Seed a ConsoleAction so the job can mirror live output into it. The
        // page renders this row through the shared console-action banner — the
        // same component used for webserver applies and every other workspace
        // op — instead of a bespoke per-page output box. The job advances the
        // row through running → completed/failed via ConsoleEmitter.
        $consoleActionId = $this->seedConsoleActionRun($server, self::OP_CONSOLE_KIND, $label);

        ServerManageRemoteSshJob::dispatch(
            $server->id,
            $id,
            $taskName,
            $inlineBash,
            $timeoutSeconds ?? (int) config('task-runner.default_timeout', 60),
            $flashSuccess,
            $logRow->id,
            null,
            $consoleActionId,
        );
    }
}
