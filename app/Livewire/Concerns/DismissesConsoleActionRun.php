<?php

namespace App\Livewire\Concerns;

use App\Models\ConsoleAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared "dismiss banner" handler for components that render
 * `livewire.partials.console-action-banner-static`. The partial's button calls
 * `dismissConsoleActionRun`; consuming components implement
 * {@see consoleActionSubject()} to return the morph target (a Site, Server, or
 * other Eloquent model) the banner is scoped to. In-flight (non-stale) rows are
 * protected from dismissal so a click can never clobber a running worker.
 */
trait DismissesConsoleActionRun
{
    abstract protected function consoleActionSubject(): Model;

    public function dismissConsoleActionRun(string $runId): void
    {
        $row = $this->findOwnedConsoleAction($runId);

        if ($row === null) {
            return;
        }

        if ($row->isInFlight() && ! $row->isStale()) {
            return;
        }

        $row->forceFill(['dismissed_at' => now()])->save();
    }

    public function cancelConsoleActionRun(string $runId): void
    {
        $row = $this->findOwnedConsoleAction($runId);

        if ($row === null || ! $row->isCancellable()) {
            return;
        }

        if (method_exists($this, 'afterConsoleActionCancelled')) {
            $this->afterConsoleActionCancelled($row);
        }

        $row->refresh();
        if ($row->isInFlight()) {
            $row->forceFill([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => __('Stopped.'),
            ])->save();
        }
    }

    private function findOwnedConsoleAction(string $runId): ?ConsoleAction
    {
        $extra = method_exists($this, 'additionalConsoleActionSubjects')
            ? $this->additionalConsoleActionSubjects()
            : [];
        $subjects = array_values(array_filter(
            [$this->consoleActionSubject(), ...$extra],
            fn (mixed $subject): bool => $subject instanceof Model,
        ));

        if ($subjects === []) {
            return null;
        }

        return ConsoleAction::query()
            ->where('id', $runId)
            ->where(function ($query) use ($subjects): void {
                foreach ($subjects as $subject) {
                    $query->orWhere(function ($inner) use ($subject): void {
                        $inner->where('subject_type', $subject->getMorphClass())
                            ->where('subject_id', $subject->getKey());
                    });
                }
            })
            ->first();
    }
}
