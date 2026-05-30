<?php

namespace App\Livewire\Servers\Concerns;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ServerRemoteAccessContext;
use App\Services\Servers\ServerRemoteAccessLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Livewire-side machinery for the console-actions banner.
 *
 * Differs from {@see WritesConsoleAction} (the job-side
 * trait) in two ways:
 *
 *   - Subjects vary per call. A workspace Livewire component handles many
 *     actions against many subjects (one ServerCacheService per engine row,
 *     one ServerDatabaseEngine per engine, etc.) so we accept subject + kind
 *     as parameters rather than abstract methods.
 *   - Calls run synchronously inside the Livewire request. {@see runConsoleAction()}
 *     wraps a callback with seed → running → complete/fail lifecycle so the
 *     row is visible from the first render and reaches a terminal state before
 *     the request returns.
 *
 * The `console-action-banner-static` partial picks up the row via
 * `ConsoleAction::forSubject($subject)->notDismissed()->latest()`. Compute it
 * in the host component's render() and pass it down as a view variable.
 */
trait RunsServerConsoleActions
{
    /**
     * Persist a queued row for (subject, kind) and return the run ID.
     *
     * Auto-dismisses any prior terminal (completed/failed) rows for the same
     * (subject, kind) so the per-subject banner getter — which always shows the
     * latest non-dismissed run — doesn't get stuck on a stale completed row when
     * the operator runs the same action twice. In-flight rows are left alone so
     * a concurrent worker isn't masked.
     */
    protected function seedConsoleActionRun(Model $subject, string $kind, string $label): string
    {
        ConsoleAction::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('kind', $kind)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        $row = ConsoleAction::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'kind' => $kind,
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => auth()->id(),
            'label' => rtrim($label, " \t\n\r\0\x0B…").' …',
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        return (string) $row->id;
    }

    /**
     * Synchronous lifecycle wrapper. Seeds a row, flips to running, invokes
     * the callback with a {@see ConsoleEmitter}, marks the row completed on
     * return or failed on throw. Re-throws so the caller can render its own
     * error state above and beyond the banner.
     *
     * @template T
     *
     * @param  callable(ConsoleEmitter $emit): T  $callback
     * @return T
     */
    protected function runConsoleAction(Model $subject, string $kind, string $label, callable $callback): mixed
    {
        $id = $this->seedConsoleActionRun($subject, $kind, $label);

        if ((bool) config('server_ssh_access.log_remote_access', true)) {
            app()->instance(
                ServerRemoteAccessContext::class,
                ServerRemoteAccessContext::forLivewireConsole($label, $id, auth()->id()),
            );
        }

        DB::table('console_actions')->where('id', $id)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $emit = new ConsoleEmitter($id);

        try {
            $result = $callback($emit);

            DB::table('console_actions')->where('id', $id)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);

            return $result;
        } catch (\Throwable $e) {
            if (app()->bound(ServerRemoteAccessContext::class)) {
                app(ServerRemoteAccessContext::class)->failed = true;
            }

            DB::table('console_actions')->where('id', $id)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);

            throw $e;
        } finally {
            app(ServerRemoteAccessLogger::class)->finishContext();
        }
    }

    /**
     * Latest non-dismissed ConsoleAction row for the given subject. Pass a
     * `$kindPrefix` (e.g. 'cache_') to scope to a workspace's action family
     * so unrelated runs (notification dispatch, audit replay, …) don't leak
     * onto an unrelated banner.
     */
    protected function latestConsoleActionFor(Model $subject, ?string $kindPrefix = null): ?ConsoleAction
    {
        $query = ConsoleAction::query()
            ->forSubject($subject)
            ->notDismissed()
            ->orderByDesc('created_at');

        if ($kindPrefix !== null) {
            $query->where('kind', 'like', $kindPrefix.'%');
        }

        return $query->first();
    }
}
