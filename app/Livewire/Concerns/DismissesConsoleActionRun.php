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
        $subject = $this->consoleActionSubject();

        $row = ConsoleAction::query()
            ->where('id', $runId)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->first();

        if ($row === null) {
            return;
        }

        if ($row->isInFlight() && ! $row->isStale()) {
            return;
        }

        $row->forceFill(['dismissed_at' => now()])->save();
    }
}
