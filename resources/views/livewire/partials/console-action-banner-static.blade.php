@php
    /** @var \App\Models\ConsoleAction|null $run */
    /** @var array<string, array{running: string, completed: string, failed: string, stale: string}> $kindLabels */

    $busy = $run !== null && $run->isInFlight() && ! $run->isStale();
@endphp

<div wire:key="console-action-banner-static">
    @if ($busy)
        {{-- A wire:poll on a hidden element keeps the parent component re-rendering
             on a 4s cadence while the in-flight run is active, so the banner picks
             up running -> completed/failed transitions without a manual refresh.
             Polling stops automatically once the run goes terminal. --}}
        <div wire:poll.4s="" class="hidden" aria-hidden="true"></div>
    @endif

    @if ($run !== null)
        @php
            $kind = $kindLabels[$run->kind] ?? null;
            $subject = $run->subject;
            $host = (string) ($subject->name ?? 'host');
            $stale = $run->isStale();
            $effectiveStatus = $stale ? 'failed' : $run->status;

            // Prefer the per-dispatch label (set by the caller, e.g. "Removing
            // credential from :host …") over the generic kind copy ("Applying
            // webserver config to :host …"). Each status (running/completed/failed)
            // gets a derivation of the label, so a "Removing credential" run
            // shows "Removing credential" while busy and "Removed credential"
            // when finished.
            if (! empty($run->label)) {
                $message = match ($effectiveStatus) {
                    'completed' => preg_replace('/\s*…$/u', '', (string) $run->label).' — done.',
                    'failed' => $stale
                        ? preg_replace('/\s*…$/u', '', (string) $run->label).' — did not finish.'
                        : preg_replace('/\s*…$/u', '', (string) $run->label).' — failed.',
                    default => (string) $run->label,
                };
            } else {
                $message = match ($effectiveStatus) {
                    'queued', 'running' => $kind['running'] ?? __('Working …'),
                    'completed' => $kind['completed'] ?? __('Done.'),
                    'failed' => $stale
                        ? ($kind['stale'] ?? __('Did not finish.'))
                        : ($kind['failed'] ?? __('Failed.')),
                    default => $kind['running'] ?? __('Working …'),
                };
            }
            $message = str_replace(':host', $host, $message);

            $banner = match ($effectiveStatus) {
                'failed' => 'border-rose-200 bg-rose-50/80 text-rose-900',
                'completed' => 'border-emerald-200 bg-emerald-50/70 text-emerald-900',
                default => 'border-sky-200 bg-sky-50/80 text-sky-900',
            };

            $busyRow = $run->isInFlight() && ! $stale;
            $lines = $run->lines();
            $defaultExpanded = $busyRow || $effectiveStatus === 'failed';
        @endphp

        <div
            class="mb-2 overflow-hidden rounded-xl border {{ $banner }} text-sm shadow-sm w-full"
            role="status"
            aria-live="polite"
            wire:key="console-action-run-{{ $run->id }}"
            x-data="{ expanded: @js($defaultExpanded) }"
        >
            <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/70 ring-1 ring-current/20">
                        @if ($busyRow)
                            <x-spinner variant="forest" />
                        @elseif ($effectiveStatus === 'completed')
                            <x-heroicon-o-check-circle class="h-4 w-4" />
                        @elseif ($effectiveStatus === 'failed')
                            <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
                        @else
                            <x-heroicon-o-information-circle class="h-4 w-4" />
                        @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold leading-tight">{{ $message }}</p>
                        @if (! $busyRow && $run->error)
                            <p class="mt-0.5 break-all text-xs opacity-80">{{ $run->error }}</p>
                        @elseif ($run->started_at)
                            <p class="mt-0.5 text-xs opacity-70">{{ __('Started :time', ['time' => $run->started_at->diffForHumans()]) }}</p>
                        @endif
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                    @if (! $busyRow)
                        <button
                            type="button"
                            wire:click="dismissConsoleActionRun('{{ $run->id }}')"
                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-current/20 bg-white px-2.5 py-1.5 text-xs font-medium shadow-sm hover:bg-white/80"
                        >
                            <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                            {{ __('Dismiss') }}
                        </button>
                    @endif
                    <button
                        type="button"
                        x-on:click="expanded = !expanded"
                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-current/20 bg-white px-2.5 py-1.5 text-xs font-medium shadow-sm hover:bg-white/80"
                        x-bind:aria-expanded="expanded.toString()"
                    >
                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5 transition-transform" x-bind:class="expanded ? 'rotate-180' : ''" />
                        <span x-text="expanded ? @js(__('Hide output')) : @js(__('View output'))"></span>
                    </button>
                </div>
            </div>

            <div x-show="expanded" x-cloak class="border-t border-current/15 bg-white/70 px-4 py-3">
                @if (empty($lines))
                    <p class="text-xs opacity-80">
                        {{ $busyRow
                            ? __('No output yet — the worker may still be picking up the job.')
                            : __('No output recorded.') }}
                    </p>
                @else
                    <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100" x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">@foreach ($lines as $entry)@php
    $tone = match ($entry['level']) {
        'step' => 'text-sky-300',
        'warn' => 'text-amber-300',
        'error' => 'text-rose-300',
        'success' => 'text-emerald-300',
        default => 'text-emerald-100',
    };
    $prefix = $entry['source'] !== null ? '['.$entry['source'].'] ' : '';
@endphp<span class="{{ $tone }}">{{ $prefix }}{{ $entry['line'] }}</span>
@endforeach</pre>
                @endif
            </div>
        </div>
    @endif
</div>
