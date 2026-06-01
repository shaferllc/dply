@php
    /** @var \App\Models\ServerCacheService $row */
    /** @var string $card */
    /** @var array<string, string> $engineLabels */
    $engineLabel = $engineLabels[$row->engine] ?? ucfirst($row->engine);
    $entries = $slowlogEntries ?? null;
    $error = $slowlogError ?? null;
@endphp

<div
    class="{{ $card ?? 'dply-card overflow-hidden' }} p-6 sm:p-8"
    wire:init="loadSlowlog"
    wire:poll.10s="loadSlowlog"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine — slowlog', ['engine' => $engineLabel]) }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Commands that crossed the slowlog-log-slower-than threshold (10ms default). Most recent 32 entries; auto-refreshes every 10 seconds.') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2 self-start whitespace-nowrap">
            <button
                type="button"
                wire:click="loadSlowlog"
                wire:loading.attr="disabled"
                wire:target="loadSlowlog"
                class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
            >
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                <span wire:loading.remove wire:target="loadSlowlog">{{ __('Rescan') }}</span>
                <span wire:loading wire:target="loadSlowlog">{{ __('Scanning…') }}</span>
            </button>
            @if (is_array($entries))
                <button
                    type="button"
                    wire:click="openConfirmActionModal('resetSlowlog', [], @js(__('Clear slowlog?')), @js(__('Empties the engine\'s slowlog ring buffer. Use to start observing fresh after a perf investigation.')), @js(__('Clear slowlog')), false)"
                    wire:loading.attr="disabled"
                    wire:target="resetSlowlog"
                    class="shrink-0 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    {{ __('Reset') }}
                </button>
            @endif
        </div>
    </div>

    @if (! empty($slowlogFromCache) && is_array($entries))
        <p class="mt-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-amber-900">
            <x-heroicon-o-clock class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>{{ __('Showing cached snapshot') }}@if (! empty($slowlogCachedAt)) {{ __('from :time', ['time' => \Illuminate\Support\Carbon::parse($slowlogCachedAt)->diffForHumans()]) }}@endif. {{ __('Refreshing in the background — values update on the next poll tick.') }}</span>
        </p>
    @endif

    @if ($error)
        <p class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">{{ $error }}</p>
    @elseif ($entries === null)
        <p class="mt-4 text-xs text-brand-mist">{{ __('Loading…') }}</p>
    @elseif ($entries === [])
        <div class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
            <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('Slowlog is empty') }}</p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">{{ __('No commands have crossed the slowlog threshold since the engine started or the log was last reset.') }}</p>
        </div>
    @else
        <div class="mt-4 overflow-x-auto rounded-lg border border-brand-ink/10">
            <table class="min-w-full divide-y divide-brand-ink/10 text-xs">
                <thead class="bg-brand-sand/30 text-[10px] uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th scope="col" class="px-3 py-2 text-left font-semibold">{{ __('Time') }}</th>
                        <th scope="col" class="px-3 py-2 text-right font-semibold">{{ __('Duration') }}</th>
                        <th scope="col" class="px-3 py-2 text-left font-semibold">{{ __('Command') }}</th>
                        <th scope="col" class="px-3 py-2 text-left font-semibold">{{ __('Client') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5 bg-white">
                    @foreach ($entries as $entry)
                        @php
                            $durationMs = $entry['duration_us'] / 1000;
                            $slowClass = $durationMs >= 100 ? 'text-rose-700' : ($durationMs >= 50 ? 'text-amber-700' : 'text-brand-ink');
                            $clientLabel = $entry['client_name'] !== ''
                                ? $entry['client_name'].' · '.$entry['client_addr']
                                : $entry['client_addr'];
                        @endphp
                        <tr>
                            <td class="px-3 py-2 align-top text-brand-moss" title="{{ $entry['at']->toDateTimeString() }}">{{ $entry['at']->diffForHumans() }}</td>
                            <td class="px-3 py-2 align-top text-right font-mono font-semibold tabular-nums {{ $slowClass }}">{{ number_format($durationMs, $durationMs < 10 ? 2 : 1) }}ms</td>
                            <td class="px-3 py-2 align-top font-mono text-brand-ink">
                                <span class="block max-w-md truncate" title="{{ $entry['command'] }}">{{ $entry['command'] }}</span>
                            </td>
                            <td class="px-3 py-2 align-top font-mono text-brand-moss">{{ $clientLabel ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-[11px] text-brand-mist">{{ __('Threshold lives in CONFIG GET slowlog-log-slower-than. Tune it from the Configure subtab if you need finer-grained capture.') }}</p>
    @endif
</div>
