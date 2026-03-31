@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    /** Entry methods only — omit loadSystemLog and poll so auto-refresh / Reverb merges do not flash a layer over the log. */
    $logLoadingTargets = 'selectLogSource,selectLogSourceFromMenu,loadSystemLogIfEmpty,refreshSystemLog,refreshSystemLogAndCloseMenu,applyLogViewerSettingsAndCloseMenu,applyLogTailLines,setLogTimeRange,setLogTimeRangeFromSelect,loadLogPreset,saveLogPreset,deleteLogPreset';
    $currentLogDef = $logSources[$logKey] ?? [];
    $logFetchedHuman = $logLastFetchedAt
        ? \Illuminate\Support\Carbon::parse($logLastFetchedAt)->timezone(config('app.timezone'))->format('Y-m-d H:i:s T')
        : null;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="logs"
    :title="__('Logs')"
    :description="__('View Dply activity and allowlisted system logs (tail over SSH). Paths are fixed in config for safety.')"
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

    @if ($server->workspace)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
            <p class="font-semibold">{{ __('Project troubleshooting shortcut') }}</p>
            <p class="mt-1 leading-relaxed text-brand-moss">
                {{ __('Use server logs here for machine-level debugging, then jump to the project operations page to compare activity across related resources in the same project.') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project operations') }}</a>
                <a href="{{ route('projects.delivery', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project delivery') }}</a>
            </div>
        </div>
    @endif

    <div class="rounded-2xl border border-brand-ink/10 bg-white px-5 py-4 text-sm text-brand-ink shadow-sm">
        <div class="grid gap-3 lg:grid-cols-3">
            <div>
                <p class="font-semibold">{{ __('Use logs to verify symptoms') }}</p>
                <p class="mt-1 leading-relaxed text-brand-moss">{{ __('Check whether an incident is code, capacity, or host-related before reaching for a rollback.') }}</p>
            </div>
            <div>
                <p class="font-semibold">{{ __('Pair with metrics and project ops') }}</p>
                <p class="mt-1 leading-relaxed text-brand-moss">{{ __('This page is strongest when used beside Metrics and the project operations tab so you can line up events across the stack.') }}</p>
            </div>
            <div>
                <p class="font-semibold">{{ __('Capture evidence for recovery') }}</p>
                <p class="mt-1 leading-relaxed text-brand-moss">{{ __('Saved views, exports, and share links make it easier to hand off context when another operator needs to jump in.') }}</p>
            </div>
        </div>
    </div>

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
                copyLogOutput() {
                    const el = document.querySelector('[data-log-viewer-output]');
                    if (!el) {
                        return;
                    }
                    const t = el.innerText ?? '';
                    if (t === '' || !navigator.clipboard?.writeText) {
                        return;
                    }
                    navigator.clipboard.writeText(t);
                },
                downloadLogOutput() {
                    const el = document.querySelector('[data-log-viewer-output]');
                    if (!el) {
                        return;
                    }
                    const t = el.innerText ?? '';
                    if (t === '') {
                        return;
                    }
                    const blob = new Blob([t], { type: 'text/plain;charset=utf-8' });
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'server-log-' + new Date().toISOString().slice(0, 19).replace(/:/g, '-') + '.txt';
                    a.click();
                    URL.revokeObjectURL(a.href);
                },
                findNeedle: '',
                findMatchIdx: -1,
                findLines: [],
                syncFindLines() {
                    const el = document.querySelector('[data-log-viewer-output]');
                    this.findLines = el ? (el.innerText || '').split(/\r?\n/) : [];
                },
                findNextMatch() {
                    const q = (this.findNeedle || '').trim();
                    if (!q) {
                        return;
                    }
                    this.syncFindLines();
                    const low = q.toLowerCase();
                    const n = this.findLines.length;
                    if (n === 0) {
                        return;
                    }
                    let i = this.findMatchIdx + 1;
                    for (let step = 0; step < n; step++, i++) {
                        if (i >= n) {
                            i = 0;
                        }
                        if ((this.findLines[i] || '').toLowerCase().includes(low)) {
                            this.findMatchIdx = i;
                            this.scrollLogToLine(i);
                            return;
                        }
                    }
                },
                findPrevMatch() {
                    const q = (this.findNeedle || '').trim();
                    if (!q) {
                        return;
                    }
                    this.syncFindLines();
                    const low = q.toLowerCase();
                    const n = this.findLines.length;
                    if (n === 0) {
                        return;
                    }
                    let i = this.findMatchIdx <= 0 ? n - 1 : this.findMatchIdx - 1;
                    for (let step = 0; step < n; step++) {
                        if ((this.findLines[i] || '').toLowerCase().includes(low)) {
                            this.findMatchIdx = i;
                            this.scrollLogToLine(i);
                            return;
                        }
                        i = i <= 0 ? n - 1 : i - 1;
                    }
                },
                scrollLogToLine(lineIndex) {
                    const el = document.querySelector('[data-log-viewer-output]');
                    if (!el) {
                        return;
                    }
                    const lh = parseFloat(window.getComputedStyle(el).lineHeight) || 22.5;
                    el.scrollTop = Math.max(0, lineIndex * lh - el.clientHeight * 0.35);
                },
                async pinCurrentLine() {
                    const el = document.querySelector('[data-log-viewer-output]');
                    if (!el) {
                        return;
                    }
                    const q = (this.findNeedle || '').trim();
                    if (!q) {
                        return;
                    }
                    this.syncFindLines();
                    const low = q.toLowerCase();
                    let idx = this.findMatchIdx >= 0 ? this.findMatchIdx : this.findLines.findIndex((l) => (l || '').toLowerCase().includes(low));
                    if (idx < 0) {
                        return;
                    }
                    const line = this.findLines[idx] || '';
                    const enc = new TextEncoder();
                    const hashBuffer = await crypto.subtle.digest('SHA-256', enc.encode(line));
                    const hash = Array.from(new Uint8Array(hashBuffer))
                        .map((b) => b.toString(16).padStart(2, '0'))
                        .join('');
                    $wire.pinLogLine(hash, '');
                },
            }"
            x-on:log-viewer-output-updated.window="const __lo = document.querySelector('[data-log-viewer-output]'); if (__lo) __lo.scrollTop = 0; syncFindLines()"
            x-on:keydown.window="logViewerShortcut($event)"
            x-on:keydown.escape.window="$wire.closeLogSourceMenu(); $wire.closeLogOptionsMenu()"
        >
            <div class="min-h-0 w-full p-3 sm:p-4">
                <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:gap-3">
                    <div
                        class="relative shrink-0 lg:max-w-[min(100%,20rem)]"
                        x-data
                        x-on:click.outside="$wire.closeLogSourceMenu()"
                    >
                        <button
                            type="button"
                            wire:click="toggleLogSourceMenu"
                            title="{{ __('Log source') }}"
                            class="box-border flex h-10 w-full min-w-0 min-h-10 items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 text-left text-sm font-medium leading-none text-brand-ink shadow-sm hover:bg-brand-sand/40"
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
                    <div class="min-w-0 flex-1 sm:flex sm:flex-col sm:gap-1.5">
                        <label for="log-filter" class="sr-only">{{ __('Filter lines') }}</label>
                        <input
                            id="log-filter"
                            type="search"
                            wire:model.live.debounce.300ms="logFilter"
                            placeholder="{{ __('Filter lines… (regex when enabled)') }}"
                            class="box-border block h-9 w-full rounded-lg border border-brand-ink/15 bg-white px-3 text-sm leading-none text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            autocomplete="off"
                        />
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                            <label class="inline-flex cursor-pointer items-center gap-1.5">
                                <input type="checkbox" wire:model.live="logFilterUseRegex" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                                <span>{{ __('Regex') }}</span>
                            </label>
                            <label class="inline-flex cursor-pointer items-center gap-1.5">
                                <input type="checkbox" wire:model.live="logFilterInvert" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                                <span>{{ __('Invert match') }}</span>
                            </label>
                            <label class="inline-flex cursor-pointer items-center gap-1.5">
                                <input type="checkbox" wire:model.live="logShowLineNumbers" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                                <span>{{ __('Line numbers') }}</span>
                            </label>
                            <span class="ms-auto tabular-nums text-brand-mist" aria-live="polite">
                                {{ __(':shown / :total lines', ['shown' => $logFilteredLines, 'total' => $logTotalLines]) }}
                            </span>
                        </div>
                        @if ($logFilterError)
                            <p class="text-xs font-medium text-amber-800">{{ $logFilterError }}</p>
                        @endif
                    </div>
                    <div
                        class="grid w-full shrink-0 grid-cols-3 gap-1.5 lg:w-[min(100%,17.5rem)] lg:gap-2"
                    >
                        <button
                            type="button"
                            x-on:click="copyLogOutput()"
                            class="box-border inline-flex h-9 items-center justify-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            title="{{ __('Copy visible log text') }}"
                        >
                            <x-heroicon-o-clipboard class="h-3.5 w-3.5 shrink-0 text-brand-moss" />
                            <span class="truncate">{{ __('Copy') }}</span>
                        </button>
                        <button
                            type="button"
                            x-on:click="downloadLogOutput()"
                            class="box-border inline-flex h-9 items-center justify-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            title="{{ __('Download visible log as .txt') }}"
                        >
                            <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5 shrink-0 text-brand-moss" />
                            <span class="truncate">{{ __('Download') }}</span>
                        </button>
                        <div
                            class="relative min-w-0"
                            x-data
                            x-on:click.outside="$wire.closeLogOptionsMenu()"
                        >
                            <button
                                type="button"
                                wire:click="toggleLogOptionsMenu"
                                class="box-border inline-flex h-9 w-full min-w-0 items-center justify-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 text-xs font-medium leading-none text-brand-ink shadow-sm hover:bg-brand-sand/40"
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
                                    class="absolute end-0 z-50 mt-2 w-[min(calc(100vw-2rem),20rem)] rounded-xl border border-brand-ink/10 bg-white p-5 shadow-lg shadow-brand-ink/10"
                                    @click.stop
                                >
                                    <p class="mb-4 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Fetch & display') }}</p>
                                    <div class="space-y-5">
                                        <div>
                                            <label for="log-tail-lines" class="mb-2 block text-xs font-medium text-brand-moss">{{ __('Lines to tail') }}</label>
                                            <input
                                                id="log-tail-lines"
                                                type="number"
                                                min="50"
                                                max="5000"
                                                step="50"
                                                wire:model.number="logTailLines"
                                                class="box-border h-10 w-full rounded-lg border border-brand-ink/15 bg-white px-3 text-sm leading-none text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                            />
                                        </div>
                                        <div>
                                            <label for="log-display-lines" class="mb-2 block text-xs font-medium text-brand-moss">{{ __('Lines visible') }}</label>
                                            <input
                                                id="log-display-lines"
                                                type="number"
                                                min="2"
                                                max="50"
                                                step="1"
                                                wire:model.number="logDisplayLines"
                                                class="box-border h-10 w-full rounded-lg border border-brand-ink/15 bg-white px-3 text-sm leading-none text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                            />
                                        </div>
                                        <div>
                                            <label class="mb-2 flex items-center gap-2 text-xs font-medium text-brand-moss">
                                                <input type="checkbox" wire:model="logAutoRefresh" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                                                {{ __('Auto-refresh (poll)') }}
                                            </label>
                                            <p class="mb-2 text-[10px] text-brand-mist">{{ __('Follows the log by re-fetching on an interval. Backs off after errors.') }}</p>
                                            <label for="log-auto-refresh-sec" class="sr-only">{{ __('Poll interval') }}</label>
                                            <select
                                                id="log-auto-refresh-sec"
                                                wire:model.number="logAutoRefreshSeconds"
                                                class="box-border h-10 w-full rounded-lg border border-brand-ink/15 bg-white px-3 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
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
                                            class="box-border inline-flex h-10 min-h-10 w-full items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 text-sm font-medium leading-none text-brand-ink hover:bg-brand-sand/40"
                                        >
                                            <span wire:loading.remove wire:target="applyLogViewerSettingsAndCloseMenu,applyLogTailLines">{{ __('Apply') }}</span>
                                            <span wire:loading wire:target="applyLogViewerSettingsAndCloseMenu,applyLogTailLines" class="inline-flex items-center gap-2">
                                                <x-spinner variant="forest" size="sm" />
                                                {{ __('Saving…') }}
                                            </span>
                                        </button>
                                    </div>
                                    <div class="my-5 border-t border-brand-ink/10"></div>
                                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Actions') }}</p>
                                    <div class="flex flex-col gap-3">
                                        <button
                                            type="button"
                                            wire:click="refreshSystemLogAndCloseMenu"
                                            wire:loading.attr="disabled"
                                            class="box-border inline-flex h-10 min-h-10 w-full items-center rounded-lg border border-brand-ink/15 bg-white px-3 text-left text-sm font-medium leading-none text-brand-ink hover:bg-brand-sand/40"
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
                                            class="box-border inline-flex h-10 min-h-10 w-full items-center rounded-lg px-3 text-left text-sm font-medium leading-none text-brand-moss hover:bg-brand-sand/30 hover:text-brand-ink"
                                        >
                                            {{ __('Reset filter') }}
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="clearLogDisplayAndCloseMenu"
                                            title="{{ __('Clears the text in this viewer and the live session panel. Does not change files on the server.') }}"
                                            class="box-border inline-flex h-10 min-h-10 w-full items-center rounded-lg border border-brand-ink/15 bg-white px-3 text-left text-sm font-medium leading-none text-brand-ink hover:bg-brand-sand/40"
                                        >
                                            {{ __('Clear display') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Single compact row: time window, panel toggles, in-page find (keeps log viewport high on the page) --}}
                <div
                    class="mt-2 flex flex-col gap-2 border-t border-brand-ink/10 pt-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-3 sm:gap-y-1"
                    role="toolbar"
                    aria-label="{{ __('Log view toolbar') }}"
                >
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <label for="log-time-range" class="sr-only">{{ __('Time range') }}</label>
                        <span class="hidden text-[11px] font-medium text-brand-moss sm:inline">{{ __('Time') }}</span>
                        <select
                            id="log-time-range"
                            wire:change="setLogTimeRangeFromSelect($event.target.value)"
                            class="box-border h-8 max-w-[11rem] rounded-md border border-brand-ink/15 bg-white px-2 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-1 focus:ring-brand-sage/40"
                        >
                            <option value="" @selected($logTimeRangeMinutes === null)>{{ __('All (tail)') }}</option>
                            <option value="5" @selected($logTimeRangeMinutes === 5)>{{ __('5 min') }}</option>
                            <option value="15" @selected($logTimeRangeMinutes === 15)>{{ __('15 min') }}</option>
                            <option value="60" @selected($logTimeRangeMinutes === 60)>{{ __('60 min') }}</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-1 sm:border-s sm:border-brand-ink/10 sm:ps-3" title="{{ __('Panel appearance') }}">
                        <button
                            type="button"
                            wire:click="toggleLogDarkMode"
                            class="box-border inline-flex h-8 w-8 items-center justify-center rounded-md border border-brand-ink/15 bg-white text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            title="{{ $logDarkMode ? __('Use light panel') : __('Use dark panel') }}"
                        >
                            @if ($logDarkMode)
                                <x-heroicon-o-sun class="h-4 w-4 text-amber-600" />
                            @else
                                <x-heroicon-o-moon class="h-4 w-4 text-brand-moss" />
                            @endif
                        </button>
                        <button
                            type="button"
                            wire:click="toggleLogHighContrast"
                            class="box-border inline-flex h-8 w-8 items-center justify-center rounded-md border text-xs font-bold shadow-sm hover:bg-brand-sand/40 {{ $logHighContrast ? 'border-amber-500 bg-amber-50 text-amber-950' : 'border-brand-ink/15 bg-white text-brand-ink' }}"
                            title="{{ __('High contrast') }}"
                            aria-pressed="{{ $logHighContrast ? 'true' : 'false' }}"
                        >
                            <span aria-hidden="true">Aa</span>
                        </button>
                    </div>
                    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5 sm:ps-1">
                        <label for="log-find-in-view" class="sr-only">{{ __('Find in visible log') }}</label>
                        <span class="shrink-0 text-[11px] font-medium text-brand-moss">{{ __('Find') }}</span>
                        <input
                            id="log-find-in-view"
                            type="search"
                            x-model="findNeedle"
                            x-on:keydown.enter.prevent="findNextMatch()"
                            placeholder="{{ __('In view…') }}"
                            class="box-border h-8 min-w-0 flex-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-1 focus:ring-brand-sage/40 sm:max-w-[14rem] lg:max-w-[20rem]"
                        />
                        <div class="flex shrink-0 gap-1">
                            <button
                                type="button"
                                x-on:click="findPrevMatch()"
                                class="box-border inline-flex h-8 min-w-[2.25rem] items-center justify-center rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                {{ __('Prev') }}
                            </button>
                            <button
                                type="button"
                                x-on:click="findNextMatch()"
                                class="box-border inline-flex h-8 min-w-[2.25rem] items-center justify-center rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                {{ __('Next') }}
                            </button>
                            <button
                                type="button"
                                x-on:click="pinCurrentLine()"
                                class="box-border inline-flex h-8 items-center justify-center rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                title="{{ __('Pin line matching find (SHA-256 of line)') }}"
                            >
                                {{ __('Pin') }}
                            </button>
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
                    $logPreFg = $logDarkMode ? '#e4e4e7' : '#166534';
                    $logPreBg = $logDarkMode ? '#18181b' : '#fafafa';
                    $logBorder = $logHighContrast ? 'ring-2 ring-amber-400 ring-offset-2' : '';
                @endphp
                <div
                    class="relative mt-2 overflow-hidden rounded-xl border shadow-inner {{ $logDarkMode ? 'border-zinc-600 bg-zinc-900' : 'border-zinc-300 bg-zinc-50' }} {{ $logBorder }}"
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
                        style="height: {{ $logViewerHeightRem }}rem; color: {{ $logPreFg }}; background-color: {{ $logPreBg }}; line-height: {{ $logLineHeightRem }}rem;"
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
                @if ($logBroadcastEchoSubscribable)
                    <p class="mt-2 text-xs text-brand-moss">
                        {{ __('Reverb: other open tabs on this server can mirror the same log source when you refresh or auto-refresh (no extra SSH from those tabs).') }}
                    </p>
                @endif

                <div class="mt-4 space-y-4 border-t border-brand-ink/10 pt-4">
                    <section aria-labelledby="log-export-heading" class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                        <h3 id="log-export-heading" class="mb-3 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Export & share') }}</h3>
                        <p class="mb-3 text-xs text-brand-moss">{{ __('Download or share what you see in this viewer (respects filters). JSON is the full Dply audit trail, not only the tail.') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @if ($logKey === 'dply_activity')
                                <button
                                    type="button"
                                    wire:click="downloadDplyAuditJson"
                                    class="box-border inline-flex min-h-10 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    {{ __('JSON (audit)') }}
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="downloadVisibleCsv"
                                class="box-border inline-flex min-h-10 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                {{ __('CSV (visible)') }}
                            </button>
                            <button
                                type="button"
                                wire:click="createLogShareLink"
                                class="box-border inline-flex min-h-10 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                {{ __('Share snapshot link') }}
                            </button>
                        </div>
                    </section>

                    <section aria-labelledby="log-presets-heading" class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                        <h3 id="log-presets-heading" class="mb-3 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Saved views') }}</h3>
                        <p class="mb-3 text-xs text-brand-moss">{{ __('Store filter, source, tail size, and time range for quick recall.') }}</p>
                        @if (count($logPresets) > 0)
                            <ul class="mb-4 flex flex-wrap gap-2" role="list">
                                @foreach ($logPresets as $pid => $preset)
                                    @if (is_array($preset))
                                        <li class="inline-flex items-stretch overflow-hidden rounded-lg border border-brand-ink/10 bg-brand-sand/30 text-xs shadow-sm">
                                            <button
                                                type="button"
                                                wire:click="loadLogPreset('{{ $pid }}')"
                                                class="px-3 py-2 text-left font-medium text-brand-ink hover:bg-brand-sand/50"
                                            >
                                                {{ $preset['name'] ?? $pid }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="deleteLogPreset('{{ $pid }}')"
                                                class="border-s border-brand-ink/10 px-2 text-brand-mist hover:bg-red-50 hover:text-red-700"
                                                title="{{ __('Delete preset') }}"
                                            >
                                                <span class="sr-only">{{ __('Delete') }}</span>
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <div class="min-w-0 flex-1 space-y-2">
                                <label for="log-new-preset-name" class="sr-only">{{ __('Preset name') }}</label>
                                <input
                                    id="log-new-preset-name"
                                    type="text"
                                    wire:model="newPresetName"
                                    placeholder="{{ __('Name this view…') }}"
                                    class="box-border h-10 w-full rounded-lg border border-brand-ink/15 bg-white px-3 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                />
                            </div>
                            <button
                                type="button"
                                wire:click="saveLogPreset"
                                class="box-border inline-flex h-10 shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                {{ __('Save view') }}
                            </button>
                        </div>
                    </section>

                    @if ($logPins->isNotEmpty())
                        <section aria-labelledby="log-pins-heading" class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:p-5">
                            <h3 id="log-pins-heading" class="mb-3 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Pinned lines') }}</h3>
                            <ul class="space-y-2 text-sm">
                                @foreach ($logPins as $pin)
                                    <li class="flex flex-wrap items-baseline justify-between gap-2 rounded-lg border border-brand-ink/5 bg-white/80 px-3 py-2">
                                        <span class="font-mono text-[11px] text-brand-mist">{{ \Illuminate\Support\Str::limit($pin->line_fingerprint, 16, '…') }}</span>
                                        @if ($pin->note)
                                            <span class="min-w-0 flex-1 text-brand-ink">{{ $pin->note }}</span>
                                        @endif
                                        <button
                                            type="button"
                                            wire:click="unpinLogLine('{{ $pin->id }}')"
                                            class="shrink-0 text-xs font-medium text-brand-moss hover:text-red-700"
                                        >
                                            {{ __('Remove') }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if (($logLineSummary['lines'] ?? 0) > 0 && ($remoteLogRaw ?? '') !== '')
                        <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 sm:px-5">
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Line hints (visible fetch)') }}</p>
                            <dl class="grid grid-cols-2 gap-3 text-xs sm:grid-cols-3 lg:grid-cols-5">
                                <div class="rounded-lg bg-brand-sand/40 px-3 py-2">
                                    <dt class="text-brand-mist">{{ __('HTTP 5xx') }}</dt>
                                    <dd class="font-semibold tabular-nums text-brand-ink">{{ $logLineSummary['http_5xx'] ?? 0 }}</dd>
                                </div>
                                <div class="rounded-lg bg-brand-sand/40 px-3 py-2">
                                    <dt class="text-brand-mist">{{ __('HTTP 4xx') }}</dt>
                                    <dd class="font-semibold tabular-nums text-brand-ink">{{ $logLineSummary['http_4xx'] ?? 0 }}</dd>
                                </div>
                                <div class="rounded-lg bg-brand-sand/40 px-3 py-2">
                                    <dt class="text-brand-mist">{{ __('HTTP 2xx/3xx') }}</dt>
                                    <dd class="font-semibold tabular-nums text-brand-ink">{{ $logLineSummary['http_2xx_3xx'] ?? 0 }}</dd>
                                </div>
                                <div class="rounded-lg bg-brand-sand/40 px-3 py-2">
                                    <dt class="text-brand-mist">{{ __('“error” hints') }}</dt>
                                    <dd class="font-semibold tabular-nums text-brand-ink">{{ $logLineSummary['error_keywords'] ?? 0 }}</dd>
                                </div>
                                <div class="rounded-lg bg-brand-sand/40 px-3 py-2 sm:col-span-2 lg:col-span-1">
                                    <dt class="text-brand-mist">{{ __('“warn” hints') }}</dt>
                                    <dd class="font-semibold tabular-nums text-brand-ink">{{ $logLineSummary['warn_keywords'] ?? 0 }}</dd>
                                </div>
                            </dl>
                        </div>
                    @endif

                    <details class="group rounded-lg border border-brand-ink/10 bg-white px-4 py-3 text-xs text-brand-moss open:pb-4">
                        <summary class="cursor-pointer list-none font-medium text-brand-ink marker:content-none [&::-webkit-details-marker]:hidden">
                            <span class="inline-flex items-center gap-1">
                                {{ __('How this viewer works') }}
                                <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-brand-mist transition group-open:rotate-180" />
                            </span>
                        </summary>
                        <div class="mt-3 space-y-2 border-t border-brand-ink/10 pt-3 text-brand-moss">
                            <p>{{ __('Continuous tail uses auto-refresh and Reverb for multi-tab sync. Native SSH tail -f is not used, to avoid long-lived sessions.') }}</p>
                            <p>{{ __('Newest lines at the top. Shortcuts: / focuses filter, R refreshes. Clear display does not change files on the server. “Lines visible” is viewport height; “Lines to tail” is fetch size.') }}</p>
                            <p>{{ __('Defaults and allowlisted paths: :file.', ['file' => 'config/server_system_logs.php']) }}</p>
                        </div>
                    </details>
                </div>
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
