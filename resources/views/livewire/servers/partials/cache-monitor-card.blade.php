@props([
    'engine',
    'engineLabel',
    'row',
    'runId',
    'duration' => 10,
    'payload' => null,
    'replUnlocked' => false,
    'card' => 'dply-card overflow-hidden',
])

@php
    $running = $runId !== '';
    $lines = $payload['lines'] ?? [];
    $status = $payload['status'] ?? null;
    $error = $payload['error'] ?? null;
@endphp

<div class="{{ $card }}" wire:key="cache-monitor-{{ $engine }}">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Monitor') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine — live MONITOR', ['engine' => $engineLabel]) }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Tails redis-cli MONITOR for a bounded window so you can watch traffic against this instance live. Auto-stops when the window ends.') }}</p>
        </div>
        @if (! $running && ($payload !== null))
            <button
                type="button"
                wire:click="clearMonitorOutput"
                class="inline-flex shrink-0 items-center gap-2 self-start whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
            >
                <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Clear') }}
            </button>
        @endif
    </div>

    <div class="px-6 py-6 sm:px-7">
    {{-- Default neutral tone to match the rest of the cache-workspace
         explainers (key browser, REPL, keyspace dashboard, advanced). MONITOR
         is read-only — no destructive action that warrants amber framing —
         and the visual inconsistency between cards in the same workspace
         was the original ask. --}}
    <x-explainer class="mt-4">
        <p>{{ __('MONITOR is read-only — it doesn\'t change keys — but it forces the engine to copy every command across all connections to this client. On a hot cache that costs a meaningful slice of CPU, so use a short window (5–30 s).') }}</p>
        <p>{{ __('Output is bounded at 500 lines (oldest dropped). The window stops itself even if the browser tab is closed; the audit log records the started + completed event with the line count.') }}</p>
        <p>{{ __('Pick a short window — auto-stops when it ends.') }}</p>
    </x-explainer>

    @if (! $running)
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <span class="text-xs text-brand-mist">{{ __('Window') }}</span>
            @foreach ([5, 10, 30] as $opt)
                <button
                    type="button"
                    wire:click="startMonitor({{ $opt }})"
                    wire:loading.attr="disabled"
                    wire:target="startMonitor"
                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-forest/30 bg-brand-forest/10 px-2.5 py-1.5 text-xs font-medium text-brand-forest hover:bg-brand-forest/15 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="startMonitor({{ $opt }})" class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-play class="h-3 w-3" />
                        {{ __(':n s', ['n' => $opt]) }}
                    </span>
                    <span wire:loading wire:target="startMonitor({{ $opt }})" class="inline-flex items-center gap-1.5">
                        <x-spinner variant="forest" />
                        {{ __('Starting…') }}
                    </span>
                </button>
            @endforeach
        </div>
    @else
        <div wire:poll.1000ms="pollMonitorOutput" class="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
            <span class="inline-flex items-center gap-2">
                <x-spinner variant="forest" />
                <span>{{ __('MONITOR running for :n seconds — chunks stream below as Redis emits them.', ['n' => $duration]) }}</span>
            </span>
            <button
                type="button"
                wire:click="clearMonitorOutput"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-sky-300 bg-white px-2 py-1 text-[11px] font-medium text-sky-900 hover:bg-sky-100"
                title="{{ __('Stop watching this run. The window itself auto-completes server-side.') }}"
            >
                <x-heroicon-o-stop-circle class="h-3 w-3" />
                {{ __('Stop') }}
            </button>
        </div>
    @endif

    @if ($payload === null)
        {{-- Pre-run idle state. Operator hasn't picked a window duration
             yet — body would otherwise be blank below the chip row. --}}
        <div class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
            <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No window active') }}</p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                {{ __('Pick a window duration above —') }}
                <span class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-1.5 py-0.5 align-middle text-[11px] font-medium text-brand-ink">5s</span>,
                <span class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-1.5 py-0.5 align-middle text-[11px] font-medium text-brand-ink">10s</span>,
                {{ __('or') }}
                <span class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-1.5 py-0.5 align-middle text-[11px] font-medium text-brand-ink">30s</span>
                {{ __('— to tail') }} <code class="rounded bg-white/70 px-1 py-0.5 font-mono text-[11px] text-brand-ink ring-1 ring-brand-ink/10">redis-cli MONITOR</code> {{ __('for that bounded window. Auto-stops when the window ends; safe but adds CPU pressure on a busy engine.') }}
            </p>
        </div>
    @endif

    @if ($payload !== null)
        <div class="mt-4 max-h-96 overflow-auto rounded-xl border border-brand-ink/10 bg-brand-ink/95 p-3 font-mono text-[11px] leading-relaxed text-emerald-100"
             x-data x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">
            @if (empty($lines))
                {{-- Dark-theme empty state: same shape as the REPL "no
                     commands run yet" panel — operator immediately reads
                     this as "still waiting" not "broken". --}}
                <div class="px-6 py-6 text-center">
                    <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-100/10 text-emerald-200 ring-1 ring-emerald-100/15">
                        <svg class="h-5 w-5 animate-pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="3" />
                            <path d="M12 5 L12 2 M12 22 L12 19 M5 12 L2 12 M22 12 L19 12 M7.05 7.05 L4.93 4.93 M19.07 19.07 L16.95 16.95 M7.05 16.95 L4.93 19.07 M19.07 4.93 L16.95 7.05" stroke-linecap="round" />
                        </svg>
                    </span>
                    <p class="mt-3 text-sm font-semibold text-emerald-100">{{ __('Listening…') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-emerald-100/70">
                        {{ __('MONITOR is tailing this engine for the configured window. Every command the engine processes — SET, GET, AUTH, EXPIRE, etc. — will stream in below as it happens. If nothing appears, no client has issued a command yet during this window.') }}
                    </p>
                </div>
            @else
                @foreach ($lines as $line)
                    <div class="break-all">{{ $line }}</div>
                @endforeach
            @endif
        </div>

        @if ($error)
            <p class="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $error }}</p>
        @elseif ($status === 'completed')
            @if (count($lines) === 0)
                <p class="mt-3 text-xs text-brand-moss">{{ __('Window ended with no traffic captured. Trigger something against this engine (run a command in the Console subtab, hit your app, etc.) and start another window to see it stream in.') }}</p>
            @else
                <p class="mt-3 text-xs text-brand-moss">{{ __('Window ended. Captured :n lines.', ['n' => count($lines)]) }}</p>
            @endif
        @endif
    @endif
    </div>
</div>
