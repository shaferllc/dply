<?php

namespace App\Livewire\Concerns;

use Livewire\Livewire;

/**
 * Livewire stream targets must exist in the view: wire:stream.replace="remoteSshMeta", wire:stream="remoteSshOut".
 *
 * Streaming must only run on Livewire update requests (X-Livewire header). Calling stream() during a
 * full-page render (e.g. mount) corrupts the HTML response with raw JSON.
 */
trait StreamsRemoteSshLivewire
{
    protected function resetRemoteSshStreamTargets(): void
    {
        if ($this->shouldSkipLiveRemoteStreams()) {
            return;
        }

        $this->stream('', true)->to('remoteSshMeta');
        $this->stream('', true)->to('remoteSshOut');
    }

    protected function remoteSshStreamSetMeta(string $contextLabel, string $commandShown): void
    {
        if ($this->shouldSkipLiveRemoteStreams()) {
            return;
        }

        $html = '<p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">'.e($contextLabel).'</p>'
            .'<pre class="mt-2 whitespace-pre-wrap break-all font-mono text-[11px] leading-snug text-brand-ink">'
            .e($commandShown).'</pre>';

        $this->stream($html, true)->to('remoteSshMeta');
    }

    protected function remoteSshStreamAppendStdout(string $chunk): void
    {
        if ($chunk === '' || $this->shouldSkipLiveRemoteStreams()) {
            return;
        }

        $this->stream(e($chunk))->to('remoteSshOut');
    }

    protected function shouldSkipLiveRemoteStreams(): bool
    {
        if (app()->runningUnitTests()) {
            return true;
        }

        return ! Livewire::isLivewireRequest();
    }
}
