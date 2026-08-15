<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\ServerManageRemoteSshJob;
use App\Models\Server;
use App\Models\ServerManageAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Queues a Manage script (install / services task) to run over SSH in the
 * background, and returns the remote-task id callers poll for output.
 *
 * Extracted from {@see \App\Livewire\Servers\Concerns\RunsServerPackageInstalls}
 * so the REST surface can queue the *same* task the workspace queues — the
 * concern keeps only its Livewire-specific bits (stream meta, banner copy) and
 * delegates the cache seeding / activity row / dispatch to this class.
 */
final class ServerManageScriptQueuer
{
    /**
     * Seed the polling cache, record an activity row, and dispatch the job.
     *
     * @param  ?string  $userId  Attributed operator ULID; null for system-initiated runs.
     * @return string The remote-task id to poll (ServerManageRemoteSshJob::cacheKey).
     */
    public function queue(
        Server $server,
        string $taskName,
        string $inlineBash,
        ?int $timeoutSeconds = null,
        ?string $flashSuccess = null,
        ?string $label = null,
        ?string $userId = null,
    ): string {
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
        $logRow = ServerManageAction::create([
            'server_id' => $server->id,
            'user_id' => $userId,
            'task_name' => $taskName,
            'label' => $label ?? $this->guessInstallActionLabel($taskName) ?? $taskName,
            'status' => ServerManageAction::STATUS_QUEUED,
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

        return $id;
    }

    /**
     * Best-effort human label for an install/services-* task — used in
     * the activity log row so Overview / Services panels show
     * "Install Redis" rather than "services-install:install_redis".
     */
    public function guessInstallActionLabel(string $taskName): ?string
    {
        if (! preg_match('/^services-install:(.+)$/', $taskName, $m)) {
            return null;
        }

        $def = config('server_services.install_actions', [])[$m[1]] ?? null;

        return is_array($def) && isset($def['label']) ? (string) $def['label'] : null;
    }
}
