@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    /** Entry methods only — omit loadSystemLog and poll so auto-refresh / Reverb merges do not flash a layer over the log. */
    $logLoadingTargets = 'selectLogSource,selectLogSourceFromMenu,loadSystemLogIfEmpty,refreshSystemLog,refreshSystemLogAndCloseMenu,applyLogViewerSettingsAndCloseMenu,applyLogTailLines,setLogTimeRange,setLogTimeRangeFromSelect';
    $currentLogDef = $logSources[$logKey] ?? [];
    $logFetchedHuman = $logLastFetchedAt
        ? \Illuminate\Support\Carbon::parse($logLastFetchedAt)->timezone(config('app.timezone'))->format('Y-m-d H:i:s T')
        : null;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="logs"
    :title="__('Logs')"
    :description="__('View Dply activity and system logs for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div
        id="dply-server-log-broadcast-context"
        class="hidden"
        aria-hidden="true"
        data-server-id="{{ $server->id }}"
        data-subscribe="{{ $logBroadcastEchoSubscribable ? '1' : '0' }}"
    ></div>

    @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => $logDisplayLines])

    <div
        class="{{ $card }} overflow-hidden"
        wire:init="loadSystemLogIfEmpty"
        @if ($logAutoRefresh) wire:poll.{{ $logAutoRefreshSeconds }}s="pollLogViewerRefresh" @endif
    >
        <div
            class="flex min-h-0 flex-col lg:min-h-[calc(100dvh-10.5rem)]"
            x-data="{
                logViewerFocusFilter() {
                    const el = document.getElementById('log-filter');
                    if (el && document.activeElement !== el) {
                        el.focus();
                        el.select?.();
                    }
                },
                logViewerShortcut(e) {
                    if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey) {
                        return;
                    }
                    const tag = document.activeElement?.tagName;
                    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
                        return;
                    }
                    if (e.key === '/') {
                        e.preventDefault();
                        this.logViewerFocusFilter();
                    }
                    if (e.key === 'r' || e.key === 'R') {
                        e.preventDefault();
                        $wire.refreshSystemLog();
                    }
                },
            }"
            x-on:log-viewer-output-updated.window="const __lo = document.querySelector('[data-log-viewer-output]'); if (__lo) __lo.scrollTop = 0"
            x-on:keydown.window="logViewerShortcut($event)"
            x-on:keydown.escape.window="$wire.closeLogSourceMenu(); $wire.closeLogOptionsMenu()"
        >
            <div class="min-h-0 w-full p-3 sm:p-4">
                <div class="grid gap-3 lg:grid-cols-[minmax(14rem,17rem)_minmax(0,1fr)_auto] lg:items-start">
                    <div
                        class="relative min-w-0"
                        x-data
                        x-on:click.outside="$wire.closeLogSourceMenu()"
                    >
                        <button
                            type="button"
                            wire:click="toggleLogSourceMenu"
                            title="{{ __('Log source') }}"
                            class="box-border flex h-10 w-full min-w-0 min-h-10 items-center gap-2 rounded-xl border border-brand-ink/10 bg-white/95 px-3 text-left text-sm font-medium leading-none text-brand-ink shadow-sm shadow-brand-ink/5 hover:border-brand-ink/15 hover:bg-brand-sand/25"
                            aria-haspopup="listbox"
                            aria-label="{{ __('Log source') }}: {{ __($currentLogDef['label'] ?? $logKey) }}"
                            @if ($logSourceMenuOpen) aria-expanded="true" @else aria-expanded="false" @endif
                        >
                            <x-heroicon-o-document-text class="h-4 w-4 shrink-0 text-brand-moss" />
                            <span class="min-w-0 flex-1 truncate text-brand-ink">{{ __($currentLogDef['label'] ?? $logKey) }}</span>
                            <span @class([
                                'inline-flex shrink-0 transition-transform duration-200',
                                'rotate-180' => $logSourceMenuOpen,
                            ])>
                                <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-mist" />
                            </span>
                        </button>
                        @if ($logSourceMenuOpen)
                            <div
                                wire:transition
                                class="absolute start-0 z-50 mt-2 w-[min(calc(100vw-2rem),32rem)] max-h-[min(70dvh,28rem)] overflow-y-auto rounded-xl border border-brand-ink/10 bg-white p-3 shadow-lg shadow-brand-ink/10"
                                role="listbox"
                                @click.stop
                            >
                                <nav
                                    class="space-y-1"
                                    aria-label="{{ __('Log sources') }}"
                                    wire:loading.class="pointer-events-none opacity-60"
                                    wire:target="{{ $logLoadingTargets }}"
                                >
                                    @php $lastGroup = null; @endphp
                                    @foreach ($logSources as $sourceKey => $def)
                                        @php $g = $def['group'] ?? 'other'; @endphp
                                        @if ($g !== $lastGroup)
                                            @php $lastGroup = $g; @endphp
                                            <p class="mb-1 mt-3 px-2 pt-1 text-[10px] font-bold uppercase tracking-wider text-brand-mist first:mt-0">{{ str_replace('_', ' ', $g) }}</p>
                                        @endif
                                        <button
                                            type="button"
                                            wire:click="selectLogSourceFromMenu('{{ $sourceKey }}')"
                                            wire:key="log-src-dd-{{ $sourceKey }}"
                                            @class([
                                                'flex w-full flex-col gap-0.5 rounded-lg px-3 py-2.5 text-left text-sm transition-colors',
                                                'bg-brand-sand/60 text-brand-ink' => $logKey === $sourceKey,
                                                'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $logKey !== $sourceKey,
                                            ])
                                        >
                                            <span class="font-medium leading-snug">{{ __($def['label'] ?? $sourceKey) }}</span>
                                            @if (! empty($def['path']))
                                                <span class="break-all font-mono text-[10px] leading-relaxed text-brand-mist">{{ $def['path'] }}</span>
                                            @elseif (($def['type'] ?? '') === 'dply')
                                                <span class="text-[10px] text-brand-moss">{{ __('Control plane audit trail') }}</span>
                                            @elseif (($def['type'] ?? '') === 'journal')
                                                <span class="break-all font-mono text-[10px] leading-relaxed text-brand-mist">journalctl -u {{ $def['unit'] ?? $def['unit_template'] ?? '' }}</span>
                                            @endif
                                        </button>
                                    @endforeach
                                </nav>
                            </div>
                        @endif
                    </div>
                    <div class="min-w-0 rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-3 shadow-sm shadow-brand-ink/5">
                        <label for="log-filter" class="sr-only">{{ __('Filter lines') }}</label>
                        <input
                            id="log-filter"
                            type="search"
                            wire:model.live.debounce.300ms="logFilter"
                            placeholder="{{ __('Filter visible lines') }}"
                            class="box-border block h-10 w-full rounded-xl border border-brand-ink/10 bg-white px-3.5 text-sm leading-none text-brand-ink shadow-sm shadow-brand-ink/5 placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/25"
                            autocomplete="off"
                        />
                        <div class="mt-2 flex flex-col gap-2 text-xs text-brand-moss sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white/80 px-2.5 py-1.5">
                                    <input type="checkbox" wire:model.live="logFilterUseRegex" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                                    <span>{{ __('Regex') }}</span>
                                </label>
                                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white/80 px-2.5 py-1.5">
                                    <input type="checkbox" wire:model.live="logFilterInvert" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                                    <span>{{ __('Invert match') }}</span>
                                </label>
                                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white/80 px-2.5 py-1.5">
                                    <input type="checkbox" wire:model.live="logShowLineNumbers" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                                    <span>{{ __('Line numbers') }}</span>
                                </label>
                            </div>
                            <span class="shrink-0 rounded-full bg-white/80 px-2.5 py-1.5 tabular-nums text-brand-mist sm:text-right" aria-live="polite">
                                {{ __(':shown / :total lines', ['shown' => $logFilteredLines, 'total' => $logTotalLines]) }}
                            </span>
                        </div>
                        @if ($logFilterError)
                            <p class="mt-2 text-xs font-medium text-amber-800">{{ $logFilterError }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                        <div class="min-w-0">
                            <label for="log-time-range" class="sr-only">{{ __('Time range') }}</label>
                            <select
                                id="log-time-range"
                                wire:change="setLogTimeRangeFromSelect($event.target.value)"
                                class="box-border h-10 min-w-[9.25rem] rounded-xl border border-brand-ink/10 bg-white px-3 text-sm text-brand-ink shadow-sm shadow-brand-ink/5 focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/25"
                                title="{{ __('Time range') }}"
                            >
                                <option value="" @selected($logTimeRangeMinutes === null)>{{ __('Time: All') }}</option>
                                <option value="5" @selected($logTimeRangeMinutes === 5)>{{ __('Time: 5 min') }}</option>
                                <option value="15" @selected($logTimeRangeMinutes === 15)>{{ __('Time: 15 min') }}</option>
                                <option value="60" @selected($logTimeRangeMinutes === 60)>{{ __('Time: 60 min') }}</option>
                            </select>
                        </div>
                        <button
                            type="button"
                            wire:click="refreshSystemLog"
                            wire:loading.attr="disabled"
                            class="box-border inline-flex h-10 min-w-[7rem] items-center justify-center gap-1.5 rounded-xl border border-brand-ink/10 bg-white px-4 text-sm font-medium leading-none text-brand-ink shadow-sm shadow-brand-ink/5 hover:border-brand-ink/15 hover:bg-brand-sand/25"
                        >
                            <span wire:loading.remove wire:target="refreshSystemLog,refreshSystemLogAndCloseMenu" class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0 text-brand-moss" />
                                <span>{{ __('Refresh') }}</span>
                            </span>
                            <span wire:loading wire:target="refreshSystemLog,refreshSystemLogAndCloseMenu" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" size="sm" />
                                {{ __('Loading…') }}
                            </span>
                        </button>
                        <div
                            class="relative min-w-0"
                            x-data
                            x-on:click.outside="$wire.closeLogOptionsMenu()"
                        >
                            <button
                                type="button"
                                wire:click="toggleLogOptionsMenu"
                                class="box-border inline-flex h-10 min-w-[7rem] items-center justify-center gap-1.5 rounded-xl border border-brand-ink/10 bg-white px-4 text-sm font-medium leading-none text-brand-ink shadow-sm shadow-brand-ink/5 hover:border-brand-ink/15 hover:bg-brand-sand/25"
                                aria-haspopup="true"
                                @if ($logOptionsMenuOpen) aria-expanded="true" @else aria-expanded="false" @endif
                            >
                                <x-heroicon-o-funnel class="h-3.5 w-3.5 shrink-0 text-brand-moss" />
                                <span class="min-w-0 truncate">{{ __('Options') }}</span>
                                <span @class([
                                    'inline-flex shrink-0 transition-transform duration-200',
                                    'rotate-180' => $logOptionsMenuOpen,
                                ])>
                                    <x-heroicon-o-chevron-down class="h-3.5 w-3.5 shrink-0 text-brand-mist" />
                                </span>
                            </button>
                            @if ($logOptionsMenuOpen)
                                <div
                                    wire:transition
                                    class="absolute end-0 z-50 mt-2 w-[min(calc(100vw-2rem),19rem)] rounded-xl border border-brand-ink/10 bg-white p-4 shadow-lg shadow-brand-ink/10"
                                    @click.stop
                                >
                                    <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Fetch & display') }}</p>
                                    <div class="space-y-3.5">
                                        <div>
                                            <label for="log-tail-lines" class="mb-1.5 block text-xs font-medium text-brand-moss">{{ __('Lines to tail') }}</label>
                                            <input
                                                id="log-tail-lines"
                                                type="number"
                                                min="50"
                                                max="5000"
                                                step="50"
                                                wire:model.number="logTailLines"
                                                class="box-border h-9 w-full rounded-lg border border-brand-ink/15 bg-white px-3 text-sm leading-none text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                            />
                                        </div>
                                        <div>
                                            <label for="log-display-lines" class="mb-1.5 block text-xs font-medium text-brand-moss">{{ __('Lines visible') }}</label>
                                            <input
                                                id="log-display-lines"
                                                type="number"
                                                min="2"
                                                max="50"
                                                step="1"
                                                wire:model.number="logDisplayLines"
                                                class="box-border h-9 w-full rounded-lg border border-brand-ink/15 bg-white px-3 text-sm leading-none text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                            />
                                        </div>
                                        <div>
                                            <label class="mb-1.5 flex items-center gap-2 text-xs font-medium text-brand-moss">
                                                <input type="checkbox" wire:model="logAutoRefresh" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                                                {{ __('Auto-refresh (poll)') }}
                                            </label>
                                            <p class="mb-1.5 text-[10px] leading-4 text-brand-mist">{{ __('Follows the log by re-fetching on an interval. Backs off after errors.') }}</p>
                                            <label for="log-auto-refresh-sec" class="sr-only">{{ __('Poll interval') }}</label>
                                            <select
                                                id="log-auto-refresh-sec"
                                                wire:model.number="logAutoRefreshSeconds"
                                                class="box-border h-9 w-full rounded-lg border border-brand-ink/15 bg-white px-3 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                            >
                                                <option value="15">{{ __('Every 15 seconds') }}</option>
                                                <option value="30">{{ __('Every 30 seconds') }}</option>
                                                <option value="60">{{ __('Every 60 seconds') }}</option>
                                            </select>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="applyLogViewerSettingsAndCloseMenu"
                                            wire:loading.attr="disabled"
                                            class="box-border inline-flex h-9 min-h-9 w-full items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 text-sm font-medium leading-none text-brand-ink hover:bg-brand-sand/40"
                                        >
                                            <span wire:loading.remove wire:target="applyLogViewerSettingsAndCloseMenu,applyLogTailLines">{{ __('Apply') }}</span>
                                            <span wire:loading wire:target="applyLogViewerSettingsAndCloseMenu,applyLogTailLines" class="inline-flex items-center gap-2">
                                                <x-spinner variant="forest" size="sm" />
                                                {{ __('Saving…') }}
                                            </span>
                                        </button>
                                    </div>
                                    <div class="my-3.5 border-t border-brand-ink/10"></div>
                                    <p class="mb-2.5 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Actions') }}</p>
                                    <div class="flex flex-col gap-2">
                                        <button
                                            type="button"
                                            wire:click="refreshSystemLogAndCloseMenu"
                                            wire:loading.attr="disabled"
                                            class="box-border inline-flex h-9 min-h-9 w-full items-center rounded-lg border border-brand-ink/15 bg-white px-3 text-left text-sm font-medium leading-none text-brand-ink hover:bg-brand-sand/40"
                                        >
                                            <span wire:loading.remove wire:target="refreshSystemLog,applyLogTailLines,applyLogViewerSettingsAndCloseMenu,refreshSystemLogAndCloseMenu">{{ __('Refresh') }}</span>
                                            <span wire:loading wire:target="refreshSystemLog,applyLogTailLines,applyLogViewerSettingsAndCloseMenu,refreshSystemLogAndCloseMenu" class="inline-flex items-center gap-2">
                                                <x-spinner variant="forest" size="sm" />
                                                {{ __('Loading…') }}
                                            </span>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="resetLogFilterAndCloseMenu"
                                            class="box-border inline-flex h-8 min-h-8 w-full items-center rounded-lg px-2 text-left text-sm font-medium leading-none text-brand-moss hover:bg-brand-sand/30 hover:text-brand-ink"
                                        >
                                            {{ __('Reset filter') }}
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="clearLogDisplayAndCloseMenu"
                                            title="{{ __('Clears the text in this viewer and the live session panel. Does not change files on the server.') }}"
                                            class="box-border inline-flex h-9 min-h-9 w-full items-center rounded-lg border border-brand-ink/15 bg-white px-3 text-left text-sm font-medium leading-none text-brand-ink hover:bg-brand-sand/40"
                                        >
                                            {{ __('Clear display') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($remoteLogError)
                    <div class="mt-4 rounded-xl border border-amber-200/80 bg-amber-50/90 px-4 py-3 text-sm text-amber-950">{{ $remoteLogError }}</div>
                @endif
                @php
                    $logViewerVisibleLines = max(2, min(50, (int) $logDisplayLines));
                    $logLineHeightRem = 1.5;
                    $logViewerHeightRem = $logViewerVisibleLines * $logLineHeightRem;
                @endphp
                <div
                    class="relative mt-3 overflow-hidden rounded-xl border border-zinc-300 bg-zinc-50 shadow-inner"
                >
                    <div
                        wire:loading.delay.shortest
                        wire:target="{{ $logLoadingTargets }}"
                        class="pointer-events-none absolute end-2 top-2 z-10 flex items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white/95 px-2 py-1 text-xs font-medium text-brand-ink shadow-sm"
                        role="status"
                        aria-live="polite"
                    >
                        <x-spinner variant="emerald" size="sm" class="shrink-0" />
                        <span>{{ __('Loading…') }}</span>
                    </div>
                    <pre
                        x-ref="logOutputPre"
                        data-log-viewer-output
                        style="height: {{ $logViewerHeightRem }}rem; color: #166534; background-color: #fafafa; line-height: {{ $logLineHeightRem }}rem;"
                        class="overflow-y-auto px-4 py-3 font-mono text-xs leading-6 whitespace-pre-wrap break-all [scrollbar-color:rgb(82 82 91 / 0.45)_transparent]"
                        role="log"
                    >{{ $remoteLogOutput !== null && $remoteLogOutput !== '' ? $remoteLogOutput : ($remoteLogError ? '' : __('No output yet. Choose a log and press Refresh.')) }}</pre>
                </div>
                @if ($logFetchedHuman)
                    <p class="mt-2 text-xs text-brand-mist">
                        {{ __('Last fetch: :time — :lines lines, :kb KB', [
                            'time' => $logFetchedHuman,
                            'lines' => $logTotalLines,
                            'kb' => number_format($logLastFetchRawBytes / 1024, 1),
                        ]) }}@if ($logLastFetchTruncated){{ ' '.__('(truncated for UI)') }}@endif
                    </p>
                @endif
                @if ($logAutoRefresh && $logPollBackoffUntil && time() < $logPollBackoffUntil)
                    <p class="mt-1 text-xs text-amber-800">
                        {{ __('Auto-refresh paused until :time after repeated errors.', [
                            'time' => \Illuminate\Support\Carbon::createFromTimestamp($logPollBackoffUntil)->timezone(config('app.timezone'))->format('H:i:s'),
                        ]) }}
                    </p>
                @endif
            </div>
        </div>
    </div>

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
