<?php

namespace App\Livewire;

use App\Models\ConsoleAction;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Page-top console banner that any subject (Site, Server, …) can mount with
 *   <livewire:console-action-banner :subject="$site" />
 * to surface every in-flight + recently-completed console_actions row.
 *
 * Stacks: if a subject has multiple non-dismissed runs (a sync and an apply
 * overlapping, say), the component renders one banner per run. Polls itself
 * while at least one run is in-flight; stops polling once everything's
 * terminal so quiet pages don't keep hitting the DB.
 *
 * State on the row is the source of truth — this component holds only the
 * subject-id pair, the rest is read fresh on every render.
 */
class ConsoleActionBanner extends Component
{
    #[Locked]
    public string $subjectType = '';

    #[Locked]
    public string $subjectId = '';

    public function mount(string $subjectType, string $subjectId): void
    {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
    }

    /**
     * Polled by `wire:poll` while any run is in-flight; the component re-renders
     * with the latest row state so the operator sees status/output progress
     * without a manual refresh.
     */
    public function refreshState(): void
    {
        // No-op — render() does the read. Method exists so the poll target resolves.
    }

    public function dismiss(string $runId): void
    {
        $row = $this->fetchRuns()->firstWhere('id', $runId);
        if ($row === null) {
            return;
        }

        // Only dismiss terminal (or stale) rows. Live runs are protected so a
        // miscued click doesn't make the banner vanish while work is happening.
        if ($row->isInFlight() && ! $row->isStale()) {
            return;
        }

        $row->forceFill(['dismissed_at' => now()])->save();
    }

    public function render(): View
    {
        $runs = $this->subjectId === '' ? collect() : $this->fetchRuns();
        $busy = $runs->contains(fn (ConsoleAction $r): bool => $r->isInFlight() && ! $r->isStale());

        return view('livewire.console-action-banner', [
            'runs' => $runs,
            'kindLabels' => (array) config('console_actions.kinds', []),
            'busy' => $busy,
        ]);
    }

    /**
     * @return Collection<int, ConsoleAction>
     */
    private function fetchRuns(): Collection
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->subjectType)
            ->where('subject_id', $this->subjectId)
            ->whereNull('dismissed_at')
            ->orderBy('created_at')
            ->get();
    }
}
