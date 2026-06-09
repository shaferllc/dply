<?php

namespace App\Jobs\Concerns;

use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Job-side machinery for the console-actions banner.
 *
 * Implementing classes declare three things:
 *   - {@see consoleSubject()} returns the Eloquent model the run is "about"
 *     (the page that should show this banner).
 *   - {@see consoleKind()} returns the slug from config('console_actions.kinds').
 *   - {@see triggeringUserId()} optionally returns the user who dispatched the
 *     run; null is fine for system-driven dispatches.
 *
 * The trait then exposes:
 *   - {@see seedQueuedConsoleAction()} — called from the dispatch site (or the
 *     job constructor) to persist a row before the worker picks the job up,
 *     so the banner appears immediately rather than after first poll.
 *   - {@see beginConsoleAction()} — called at the top of handle() to flip
 *     status to running and materialise an emitter the worker streams into.
 *   - {@see completeConsoleAction()} / {@see failConsoleAction()} — terminal
 *     transitions; banner stops polling once it sees these.
 *
 * The trait does NOT manage uniqueness — implementing jobs should still
 * `implements ShouldBeUnique` and provide a uniqueId() that combines subject
 * + kind. This keeps the "is there an in-flight kind?" question on the queue
 * driver where it belongs.
 */
trait WritesConsoleAction
{
    private ?string $consoleRunId = null;

    /**
     * Pin the worker to a ConsoleAction row the UI seeded before dispatch
     * (e.g. Livewire's seedQueuedConsoleAction) so the banner label and output
     * stay on the same run the operator just triggered.
     */
    protected function bindConsoleRunId(?string $runId): void
    {
        if ($runId !== null && $runId !== '') {
            $this->consoleRunId = $runId;
        }
    }

    /**
     * The model whose page should host the banner. Sites and Servers are the
     * obvious candidates today.
     */
    abstract protected function consoleSubject(): Model;

    /**
     * Slug from config('console_actions.kinds') keys.
     */
    abstract protected function consoleKind(): string;

    /**
     * Override to associate the run with the operator who dispatched it.
     */
    protected function triggeringUserId(): ?string
    {
        return null;
    }

    /**
     * Create a queued row before dispatch (or as the first thing handle() does).
     * Returns the run ID so the dispatcher can stash it / show an immediate
     * pending banner without waiting for the worker.
     *
     * Auto-dismisses any prior terminal (completed/failed) rows for the same
     * subject so the page-top banner — which always shows the latest non-dismissed
     * run — stays "the one slot everything routes through". Without this, the
     * stale completed banner would resurface the moment the operator dismissed
     * the newer run, which is confusing.
     */
    protected function seedQueuedConsoleAction(): string
    {
        $subject = $this->consoleSubject();

        ConsoleAction::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        $row = ConsoleAction::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'kind' => $this->consoleKind(),
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => $this->triggeringUserId(),
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        $this->consoleRunId = (string) $row->id;

        return $this->consoleRunId;
    }

    /**
     * Mark the run as running and return an emitter the worker streams into.
     * Idempotent — handle() can call this even if the dispatcher already
     * seeded a queued row.
     */
    protected function beginConsoleAction(): ConsoleEmitter
    {
        $subject = $this->consoleSubject();

        // Reuse a row if the dispatcher already seeded one — we identify it
        // by (subject, kind, status in [queued, running], not dismissed), or
        // by an explicit bindConsoleRunId() from the dispatch site.
        if ($this->consoleRunId === null) {
            $existing = ConsoleAction::query()
                ->forSubject($subject)
                ->ofKind($this->consoleKind())
                ->notDismissed()
                ->inFlight()
                ->orderByDesc('created_at')
                ->first();

            $this->consoleRunId = $existing?->id ?? $this->seedQueuedConsoleAction();
        }

        DB::table('console_actions')->where('id', $this->consoleRunId)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->update([
                'status' => ConsoleAction::STATUS_RUNNING,
                'started_at' => DB::raw('coalesce(started_at, now())'),
                'updated_at' => now(),
            ]);

        return new ConsoleEmitter($this->consoleRunId);
    }

    protected function completeConsoleAction(): void
    {
        if ($this->consoleRunId === null) {
            return;
        }

        DB::table('console_actions')->where('id', $this->consoleRunId)->update([
            'status' => ConsoleAction::STATUS_COMPLETED,
            'finished_at' => now(),
            'error' => null,
            'updated_at' => now(),
        ]);
    }

    protected function failConsoleAction(string $error): void
    {
        if ($this->consoleRunId === null) {
            return;
        }

        DB::table('console_actions')->where('id', $this->consoleRunId)->update([
            'status' => ConsoleAction::STATUS_FAILED,
            'finished_at' => now(),
            'error' => mb_substr($error, 0, 2000),
            'updated_at' => now(),
        ]);
    }

    /**
     * Useful in tests and for ad-hoc inspection from inside handle().
     */
    protected function currentConsoleRunId(): ?string
    {
        return $this->consoleRunId;
    }
}
