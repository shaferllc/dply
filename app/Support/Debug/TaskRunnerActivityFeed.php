<?php

declare(strict_types=1);

namespace App\Support\Debug;

use App\Models\RemoteCliRun;
use App\Models\ServerManageAction;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTask;
use Illuminate\Database\Eloquent\Builder;
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
    public function recent(int $limit = 50, ?string $organizationId = null, ?string $actorUserId = null): Collection
    {
        $query = $this->queryTaskRunnerTasks($organizationId, $actorUserId)
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->limit($limit);
        $manageQuery = $this->queryManageActions($organizationId, $actorUserId)
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->limit($limit);
        $cliQuery = $this->queryRemoteCliRuns($organizationId, $actorUserId)
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->limit($limit);

        return $this->merge(
            $this->mapTaskRunnerTasks($query),
            $this->mapManageActions($manageQuery),
            $this->mapRemoteCliRuns($cliQuery),
        )
            ->sortByDesc(fn (ActivityRow $row): int => optional($row->startedAt)->getTimestamp() ?? optional($row->createdAt)->getTimestamp() ?? 0)
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, ActivityRow>
     */
    public function running(?string $organizationId = null, ?string $actorUserId = null): Collection
    {
        return $this->merge(
            $this->mapTaskRunnerTasks(
                $this->queryTaskRunnerTasks($organizationId, $actorUserId)
                    ->whereIn('status', self::RUNNING_STATUSES_TASK_RUNNER),
            ),
            $this->mapManageActions(
                $this->queryManageActions($organizationId, $actorUserId)
                    ->whereIn('status', self::RUNNING_STATUSES_MANAGE),
            ),
            $this->mapRemoteCliRuns(
                $this->queryRemoteCliRuns($organizationId, $actorUserId)
                    ->whereIn('status', self::RUNNING_STATUSES_REMOTE_CLI),
            ),
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

    /**
     * @param  Builder<TaskRunnerTask>  $query
     * @return Collection<int, ActivityRow>
     */
    private function mapTaskRunnerTasks(Builder $query): Collection
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, TaskRunnerTask> $rows */
        $rows = $query->get();

        return $rows->map(fn (TaskRunnerTask $r): ActivityRow => $this->fromTaskRunnerTask($r));
    }

    /**
     * @param  Builder<ServerManageAction>  $query
     * @return Collection<int, ActivityRow>
     */
    private function mapManageActions(Builder $query): Collection
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, ServerManageAction> $rows */
        $rows = $query->get();

        return $rows->map(fn (ServerManageAction $r): ActivityRow => $this->fromManageAction($r));
    }

    /**
     * @param  Builder<RemoteCliRun>  $query
     * @return Collection<int, ActivityRow>
     */
    private function mapRemoteCliRuns(Builder $query): Collection
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, RemoteCliRun> $rows */
        $rows = $query->get();

        return $rows->map(fn (RemoteCliRun $r): ActivityRow => $this->fromRemoteCliRun($r));
    }

    /**
     * @return Builder<TaskRunnerTask>
     */
    private function queryTaskRunnerTasks(?string $organizationId, ?string $actorUserId = null): Builder
    {
        $q = TaskRunnerTask::query()->select([
            'id', 'name', 'action', 'status', 'exit_code', 'server_id',
            'created_by', 'started_at', 'completed_at', 'created_at',
        ]);

        if ($organizationId !== null) {
            // Tasks dispatched from a Livewire request may not have a
            // server_id (e.g. org-level jobs). Allow either: row joins to
            // a server in this org, OR row has no server but is owned by
            // a user in this org. Without the OR branch the panel would
            // hide every dispatched_job that isn't server-scoped.
            $q->where(function ($outer) use ($organizationId): void {
                $outer->whereExists(function ($sub) use ($organizationId): void {
                    $sub->select(\DB::raw(1))
                        ->from('servers')
                        ->whereColumn('servers.id', 'task_runner_tasks.server_id')
                        ->where('servers.organization_id', $organizationId);
                })->orWhere(function ($noServer) use ($organizationId): void {
                    $noServer->whereNull('task_runner_tasks.server_id')
                        ->whereExists(function ($sub) use ($organizationId): void {
                            $sub->select(\DB::raw(1))
                                ->from('organization_user')
                                ->whereColumn('organization_user.user_id', 'task_runner_tasks.created_by')
                                ->where('organization_user.organization_id', $organizationId);
                        });
                });
            });
        }

        if ($actorUserId !== null) {
            $q->where('task_runner_tasks.created_by', $actorUserId);
        }

        return $q;
    }

    /**
     * @return Builder<ServerManageAction>
     */
    private function queryManageActions(?string $organizationId, ?string $actorUserId = null): Builder
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

        if ($actorUserId !== null) {
            $q->where('server_manage_actions.user_id', $actorUserId);
        }

        return $q;
    }

    /**
     * @return Builder<RemoteCliRun>
     */
    private function queryRemoteCliRuns(?string $organizationId, ?string $actorUserId = null): Builder
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

        if ($actorUserId !== null) {
            $q->where('remote_cli_runs.queued_by_user_id', $actorUserId);
        }

        return $q;
    }

    /**
     * @param  Collection<int, ActivityRow>  ...$collections
     * @return Collection<int, ActivityRow>
     */
    private function merge(Collection ...$collections): Collection
    {
        return (new Collection)->concat(...$collections);
    }

    private function fromTaskRunnerTask(TaskRunnerTask $r): ActivityRow
    {
        $status = $r->status->value;
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
        $label = $r->kind->value.' · '.((string) $r->command);

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
