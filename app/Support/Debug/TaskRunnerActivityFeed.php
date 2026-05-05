<?php

declare(strict_types=1);

namespace App\Support\Debug;

use App\Models\RemoteCliRun;
use App\Models\ServerManageAction;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Unified read view across the three persistent process-run tables for the
 * TaskRunner debug panel. Returns a normalized {@see ActivityRow} stream so
 * the Livewire component doesn't have to special-case each source.
 *
 * Output text columns are NOT loaded by recent()/running() — they're fetched
 * on demand via loadOutput($source, $id) so the list query stays cheap.
 */
final class TaskRunnerActivityFeed
{
    private const RUNNING_STATUSES_TASK_RUNNER = ['pending', 'running'];

    private const RUNNING_STATUSES_MANAGE = ['queued', 'running'];

    private const RUNNING_STATUSES_REMOTE_CLI = ['queued', 'running'];

    private const OUTPUT_DETAIL_CAP_BYTES = 262_144; // 256 KB

    /**
     * @return Collection<int, ActivityRow>
     */
    public function recent(int $limit = 50, ?string $organizationId = null): Collection
    {
        return $this->merge(
            $this->queryTaskRunnerTasks($organizationId)
                ->orderByDesc('started_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(fn (TaskRunnerTask $r) => $this->fromTaskRunnerTask($r)),
            $this->queryManageActions($organizationId)
                ->orderByDesc('started_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(fn (ServerManageAction $r) => $this->fromManageAction($r)),
            $this->queryRemoteCliRuns($organizationId)
                ->orderByDesc('started_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(fn (RemoteCliRun $r) => $this->fromRemoteCliRun($r)),
        )
            ->sortByDesc(fn (ActivityRow $row): int => optional($row->startedAt)->getTimestamp() ?? optional($row->createdAt)->getTimestamp() ?? 0)
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, ActivityRow>
     */
    public function running(?string $organizationId = null): Collection
    {
        return $this->merge(
            $this->queryTaskRunnerTasks($organizationId)
                ->whereIn('status', self::RUNNING_STATUSES_TASK_RUNNER)
                ->get()
                ->map(fn (TaskRunnerTask $r) => $this->fromTaskRunnerTask($r)),
            $this->queryManageActions($organizationId)
                ->whereIn('status', self::RUNNING_STATUSES_MANAGE)
                ->get()
                ->map(fn (ServerManageAction $r) => $this->fromManageAction($r)),
            $this->queryRemoteCliRuns($organizationId)
                ->whereIn('status', self::RUNNING_STATUSES_REMOTE_CLI)
                ->get()
                ->map(fn (RemoteCliRun $r) => $this->fromRemoteCliRun($r)),
        )->values();
    }

    public function loadOutput(string $source, string $id): ?string
    {
        $raw = match ($source) {
            'task_runner_tasks' => TaskRunnerTask::query()->whereKey($id)->value('output'),
            'server_manage_actions' => ServerManageAction::query()->whereKey($id)->value('output'),
            'remote_cli_runs' => $this->loadRemoteCliOutput($id),
            default => null,
        };

        if (! is_string($raw) || $raw === '') {
            return $raw;
        }

        if (strlen($raw) > self::OUTPUT_DETAIL_CAP_BYTES) {
            return substr($raw, 0, self::OUTPUT_DETAIL_CAP_BYTES)."\n\n…(truncated; full output is in the database)";
        }

        return $raw;
    }

    /**
     * RemoteCliRun keeps stdout / stderr separately. Concatenate so the
     * detail viewer renders both with clear delimiters.
     */
    private function loadRemoteCliOutput(string $id): ?string
    {
        $row = RemoteCliRun::query()->whereKey($id)->first(['stdout', 'stderr']);
        if ($row === null) {
            return null;
        }

        $stdout = (string) ($row->stdout ?? '');
        $stderr = (string) ($row->stderr ?? '');

        if ($stdout === '' && $stderr === '') {
            return null;
        }

        $parts = [];
        if ($stdout !== '') {
            $parts[] = "─── stdout ───\n".$stdout;
        }
        if ($stderr !== '') {
            $parts[] = "─── stderr ───\n".$stderr;
        }

        return implode("\n\n", $parts);
    }

    private function queryTaskRunnerTasks(?string $organizationId)
    {
        $q = TaskRunnerTask::query()->select([
            'id', 'name', 'action', 'status', 'exit_code', 'server_id',
            'created_by', 'started_at', 'completed_at', 'created_at',
        ]);

        if ($organizationId !== null) {
            $q->whereExists(function ($sub) use ($organizationId): void {
                $sub->select(\DB::raw(1))
                    ->from('servers')
                    ->whereColumn('servers.id', 'task_runner_tasks.server_id')
                    ->where('servers.organization_id', $organizationId);
            });
        }

        return $q;
    }

    private function queryManageActions(?string $organizationId)
    {
        $q = ServerManageAction::query()->select([
            'id', 'server_id', 'user_id', 'task_name', 'label', 'status',
            'started_at', 'finished_at', 'error_message', 'created_at',
        ]);

        if ($organizationId !== null) {
            $q->whereExists(function ($sub) use ($organizationId): void {
                $sub->select(\DB::raw(1))
                    ->from('servers')
                    ->whereColumn('servers.id', 'server_manage_actions.server_id')
                    ->where('servers.organization_id', $organizationId);
            });
        }

        return $q;
    }

    private function queryRemoteCliRuns(?string $organizationId)
    {
        $q = RemoteCliRun::query()->select([
            'id', 'site_id', 'kind', 'command', 'risk', 'mode', 'status',
            'exit_code', 'queued_by_user_id', 'started_at', 'finished_at', 'created_at',
        ]);

        if ($organizationId !== null) {
            $q->whereExists(function ($sub) use ($organizationId): void {
                $sub->select(\DB::raw(1))
                    ->from('sites')
                    ->join('servers', 'servers.id', '=', 'sites.server_id')
                    ->whereColumn('sites.id', 'remote_cli_runs.site_id')
                    ->where('servers.organization_id', $organizationId);
            });
        }

        return $q;
    }

    /**
     * @template T
     * @param  Collection<int, T>  ...$collections
     * @return Collection<int, T>
     */
    private function merge(Collection ...$collections): Collection
    {
        return (new Collection)->concat(...$collections);
    }

    private function fromTaskRunnerTask(TaskRunnerTask $r): ActivityRow
    {
        $status = $r->status?->value ?? 'unknown';
        $duration = $this->durationSeconds($r->started_at, $r->completed_at);
        $label = trim((string) ($r->name ?? '')) !== '' ? (string) $r->name : ((string) ($r->action ?? '—'));

        return new ActivityRow(
            source: 'task_runner_tasks',
            id: (string) $r->id,
            label: $label,
            commandPreview: (string) ($r->action ?? $r->name ?? ''),
            status: $status,
            exitCode: $r->exit_code,
            durationSeconds: $duration,
            startedAt: $r->started_at,
            finishedAt: $r->completed_at,
            createdAt: $r->created_at,
            serverId: $r->server_id,
            siteId: null,
            actorUserId: $r->created_by,
            errorMessage: null,
        );
    }

    private function fromManageAction(ServerManageAction $r): ActivityRow
    {
        $duration = $this->durationSeconds($r->started_at, $r->finished_at);
        $label = trim((string) ($r->label ?? '')) !== '' ? (string) $r->label : (string) $r->task_name;

        return new ActivityRow(
            source: 'server_manage_actions',
            id: (string) $r->id,
            label: $label,
            commandPreview: (string) $r->task_name,
            status: (string) $r->status,
            exitCode: null,
            durationSeconds: $duration,
            startedAt: $r->started_at,
            finishedAt: $r->finished_at,
            createdAt: $r->created_at,
            serverId: $r->server_id,
            siteId: null,
            actorUserId: $r->user_id,
            errorMessage: $r->error_message,
        );
    }

    private function fromRemoteCliRun(RemoteCliRun $r): ActivityRow
    {
        $duration = $this->durationSeconds($r->started_at, $r->finished_at);
        $label = strtoupper((string) $r->kind).' · '.((string) $r->command);

        return new ActivityRow(
            source: 'remote_cli_runs',
            id: (string) $r->id,
            label: $label,
            commandPreview: (string) $r->command,
            status: (string) $r->status,
            exitCode: $r->exit_code,
            durationSeconds: $duration,
            startedAt: $r->started_at,
            finishedAt: $r->finished_at,
            createdAt: $r->created_at,
            serverId: null,
            siteId: $r->site_id,
            actorUserId: $r->queued_by_user_id,
            errorMessage: null,
        );
    }

    private function durationSeconds(?Carbon $start, ?Carbon $end): ?int
    {
        if ($start === null) {
            return null;
        }
        $finish = $end ?? Carbon::now();

        return max(0, (int) $finish->diffInSeconds($start, true));
    }
}
