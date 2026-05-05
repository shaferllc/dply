<?php

namespace App\Livewire\Concerns;

/**
 * No-op trait kept for back-compat with ~28 callers across server / sites
 * Livewire components.
 *
 * Originally drove a per-page "Streamed output / LIVE" drawer (`wire:stream`
 * targets `remoteSshMeta` / `remoteSshOut` in
 * resources/views/livewire/servers/partials/remote-ssh-stream-panel.blade.php).
 * That partial has been removed and all live SSH/Process activity now flows
 * through the global TaskRunner debug panel
 * ({@see \App\Livewire\Debug\TaskRunnerPanel}, fed by
 * {@see \App\Support\Debug\TaskRunnerBroadcastBridge} on the org Reverb
 * channel) — visible to platform admins only.
 *
 * The methods below are deliberate no-ops so call sites compile and run
 * unchanged. A follow-up PR can excise the trait and the `use` lines.
 */
trait StreamsRemoteSshLivewire
{
    protected function resetRemoteSshStreamTargets(): void
    {
        // no-op: replaced by TaskRunner debug panel
    }

    protected function remoteSshStreamSetMeta(string $contextLabel, string $commandShown): void
    {
        // no-op: replaced by TaskRunner debug panel
    }

    protected function remoteSshStreamAppendStdout(string $chunk): void
    {
        // no-op: replaced by TaskRunner debug panel
    }

    protected function shouldSkipLiveRemoteStreams(): bool
    {
        return true;
    }
}
