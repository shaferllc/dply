<?php

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Models\ConsoleAction;
use App\Models\Server;

/**
 * "Dismiss banner" handler for server-workspace Livewire components that render
 * `livewire.partials.console-action-banner-static` against multiple per-row
 * subjects (one ServerCacheService per engine, one ServerDatabaseEngine per
 * engine, one ServerSystemdServiceState per unit, etc.).
 *
 * The single-subject variant {@see DismissesConsoleActionRun}
 * assumes the component has one subject and rejects any dismiss whose row
 * doesn't match. Per-row workspaces have many subjects on screen, so we verify
 * "the row's subject belongs to this server" instead. The host component
 * exposes `$server` (already true for every workspace via
 * {@see InteractsWithServerWorkspace}).
 *
 * In-flight non-stale rows are still protected — a click can never clobber a
 * running worker — matching the original trait's behavior.
 */
trait DismissesServerConsoleActionRun
{
    public function dismissConsoleActionRun(string $runId): void
    {
        $server = $this->server ?? null;
        if (! $server instanceof Server) {
            return;
        }

        $row = ConsoleAction::query()->whereKey($runId)->first();
        if ($row === null) {
            return;
        }

        $subject = $row->subject;
        if ($subject === null) {
            return;
        }

        $belongs = $subject instanceof Server
            ? $subject->id === $server->id
            : (isset($subject->server_id) && $subject->server_id === $server->id);

        if (! $belongs) {
            return;
        }

        if ($row->isInFlight() && ! $row->isStale()) {
            return;
        }

        $row->forceFill(['dismissed_at' => now()])->save();
    }
}
