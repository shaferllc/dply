{{--
    Replay a captured progress log, then reveal a result. For panels backed by a
    queued SSH job that streams progress frames to cache while the UI polls: the
    job often finishes faster than the poll cadence can animate, so the frames are
    written and discarded before they're ever rendered. This component sidesteps
    the timing race — on mount it replays the *captured* frames with a brief
    staggered reveal, then fades in the real result. Frames empty (e.g. a cached
    load with no scan) → it resolves to the result instantly, no replay.

    Usage:
        <x-replay-log :frames="$progress">
            ...the result markup (table / empty / error)...
        </x-replay-log>

    $frames: a list of strings, or a list of ['line' => string] rows (the shape
    WebserverCertsAggregator::progress() returns) — both are accepted.
--}}
@props(['frames' => [], 'logClass' => '', 'resultClass' => ''])
@php
    $replayLines = collect($frames)
        ->map(fn ($f) => is_array($f) ? (string) ($f['line'] ?? '') : (string) $f)
        ->filter()
        ->values()
        ->all();
@endphp
<div
    {{ $attributes }}
    x-data="{
        frames: @js($replayLines),
        shown: [],
        done: false,
        init() {
            if (this.frames.length === 0) { this.done = true; return; }
            const per = Math.max(70, Math.min(170, Math.floor(1100 / this.frames.length)));
            const step = () => {
                if (this.shown.length >= this.frames.length) { this.done = true; return; }
                this.shown.push(this.frames[this.shown.length]);
                this.$nextTick(() => { if (this.$refs.replaylog) this.$refs.replaylog.scrollTop = this.$refs.replaylog.scrollHeight; });
                setTimeout(step, per);
            };
            setTimeout(step, per);
        }
    }"
>
    <div x-show="!done" x-cloak x-ref="replaylog" class="max-h-48 overflow-y-auto px-6 py-6 font-mono text-[11px] leading-relaxed text-brand-ink/80 sm:px-7 {{ $logClass }}">
        <template x-for="(line, i) in shown" :key="i">
            <div class="flex gap-2">
                <span class="select-none text-brand-mist" aria-hidden="true">›</span>
                <span class="break-all" x-text="line"></span>
            </div>
        </template>
    </div>
    {{-- No x-cloak here on purpose: if Alpine ever fails to init, the result must
         still render rather than leaving an empty panel. Alpine processes x-show
         on load before paint, so there's no flash in the normal case. --}}
    <div x-show="done" x-transition.opacity.duration.300ms class="{{ $resultClass }}">
        {{ $slot }}
    </div>
</div>
