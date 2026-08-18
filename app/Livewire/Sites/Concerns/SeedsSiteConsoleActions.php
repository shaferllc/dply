<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Livewire\Sites\Show;
use App\Models\ConsoleAction;

/**
 * Pre-seed a queued console-action row for the current site so Test / Fix /
 * reachability banners appear the moment a job is dispatched.
 *
 * Mirrors {@see Show::seedQueuedConsoleAction()} for
 * standalone hosts (ResourceMap, etc.) that do not inherit Show.
 */
trait SeedsSiteConsoleActions
{
    protected function seedQueuedConsoleAction(string $kind, ?string $label = null): ConsoleAction
    {
        ConsoleAction::query()
            ->forSubject($this->site)
            ->notDismissed()
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        ConsoleAction::query()
            ->forSubject($this->site)
            ->notDismissed()
            ->inFlight()
            ->get()
            ->filter(fn (ConsoleAction $row): bool => $row->isStale())
            ->each(fn (ConsoleAction $row) => $row->forceFill(['dismissed_at' => now()])->save());

        return ConsoleAction::query()->create([
            'subject_type' => $this->site->getMorphClass(),
            'subject_id' => $this->site->id,
            'kind' => $kind,
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => request()->user()?->id,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }
}
