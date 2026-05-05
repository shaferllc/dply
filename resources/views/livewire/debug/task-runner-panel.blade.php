<div>
    @if (! $authorized)
        {{-- Hidden when not platform admin. Component still mounts so the
             layout @can guard plus this fallback give belt-and-suspenders
             gating without crashing on unexpected hot-reload paths. --}}
    @else
        @php
            $statusBadge = function (string $status): string {
                return match ($status) {
                    'finished', 'completed' => 'bg-emerald-500/10 text-emerald-300 ring-emerald-400/30',
                    'failed', 'timeout', 'connection_failed', 'upload_failed', 'cancelled' => 'bg-red-500/10 text-red-300 ring-red-400/30',
                    'running' => 'bg-sky-500/10 text-sky-300 ring-sky-400/30',
                    'queued', 'pending' => 'bg-amber-500/10 text-amber-300 ring-amber-400/30',
                    default => 'bg-zinc-500/10 text-zinc-300 ring-zinc-400/30',
                };
            };
            $running = $this->running;
            $recent = $this->recent;
        @endphp
        <div
            x-data="dplyTaskRunnerDebugPanel({{ \Illuminate\Support\Js::from([
                'organizationId' => $this->organizationId,
                'expanded' => $expanded,
            ]) }})"
            x-init="init()"
            class="fixed inset-x-0 bottom-0 z-40 pointer-events-none"
            wire:ignore.self
        >
            <div class="mx-auto max-w-7xl pointer-events-auto">
                {{-- Collapsed bar --}}
                <button
                    type="button"
                    wire:click="toggle"
                    @click="expanded = ! expanded"
                    class="flex w-full items-center justify-between gap-3 border-t border-x border-zinc-700/60 bg-zinc-900 px-3 py-1.5 text-[11px] font-mono text-zinc-100 shadow-lg hover:bg-zinc-800 focus:outline-none"
                    :class="expanded ? '' : 'rounded-t-md'"
                >
                    <span class="flex items-center gap-2">
                        <span class="inline-flex h-2 w-2 rounded-full"
                              :class="(liveCount > 0 || $wire.running.length > 0) ? 'bg-emerald-400 animate-pulse' : 'bg-zinc-500'"></span>
                        <span class="font-semibold uppercase tracking-wider">{{ __('TaskRunner') }}</span>
                        <span class="text-zinc-300">
                            <span x-show="liveCount > 0 || {{ $running->count() }} > 0">
                                <span x-text="Math.max(liveCount, {{ $running->count() }})"></span> {{ __('running') }}
                            </span>
                            <span x-show="liveCount === 0 && {{ $running->count() }} === 0">
                                {{ trans_choice(':count recent run|:count recent runs', $recent->count(), ['count' => $recent->count()]) }}
                            </span>
                        </span>
                    </span>
                    <span class="text-zinc-400 text-[10px]">
                        <span x-show="!expanded">▲ {{ __('open') }}</span>
                        <span x-show="expanded">▼ {{ __('close') }}</span>
                    </span>
                </button>

                {{-- Drawer --}}
                <div x-show="expanded" x-cloak
                     class="border-x border-t border-zinc-700/60 bg-zinc-950 text-zinc-100 max-h-[55vh] flex flex-col">
                    {{-- Tabs --}}
                    <div class="flex shrink-0 items-center gap-1 px-3 pt-2 text-[11px] uppercase font-mono tracking-wide">
                        @foreach (['live' => __('Live'), 'recent' => __('Recent'), 'all' => __('All')] as $key => $label)
                            <button type="button"
                                    wire:click="setTab('{{ $key }}')"
                                    class="px-2.5 py-1 rounded-t {{ $tab === $key ? 'bg-zinc-800 text-emerald-300' : 'text-zinc-400 hover:text-zinc-200' }}">
                                {{ $label }}
                                @if ($key === 'live')
                                    <span x-show="liveCount > 0" class="ml-1 inline-flex items-center justify-center rounded-full bg-emerald-500/20 px-1.5 py-0.5 text-[9px] text-emerald-300" x-text="liveCount"></span>
                                @endif
                                @if ($key === 'recent' && $recent->isNotEmpty())
                                    <span class="ml-1 inline-flex items-center justify-center rounded-full bg-zinc-700/50 px-1.5 py-0.5 text-[9px] text-zinc-300">{{ $recent->count() }}</span>
                                @endif
                            </button>
                        @endforeach
                        <span class="ml-auto text-[10px] text-zinc-500">org · {{ $this->organizationId ?? '—' }}</span>
                    </div>

                    {{-- Body: split list (left) + detail (right) --}}
                    <div class="flex flex-1 min-h-0 overflow-hidden border-t border-zinc-800">
                        <div class="w-1/2 min-w-0 overflow-y-auto">
                            {{-- LIVE: client-side ring buffer fed by Echo listener --}}
                            <div x-show="$wire.tab === 'live'">
                                <template x-for="(row, idx) in liveBuffer" :key="row.uid">
                                    <div class="px-3 py-1.5 border-b border-zinc-800/60">
                                        <div class="flex justify-between text-[10px] uppercase tracking-wide">
                                            <span class="font-semibold text-emerald-300" x-text="row.kind"></span>
                                            <span class="text-zinc-500" x-text="row.at"></span>
                                        </div>
                                        <pre class="mt-0.5 whitespace-pre-wrap break-all text-[11px] leading-snug text-zinc-200" x-text="row.preview"></pre>
                                    </div>
                                </template>
                                <p x-show="liveBuffer.length === 0" class="p-3 text-[11px] text-zinc-500">
                                    {{ __('No live activity. Trigger an SSH/Process action to see it stream here.') }}
                                </p>
                            </div>

                            {{-- RECENT / ALL: server-rendered from Activity feed --}}
                            @if ($tab === 'recent' || $tab === 'all')
                                @forelse ($recent as $row)
                                    <button type="button"
                                            wire:click="viewDetail('{{ $row->source }}', '{{ $row->id }}')"
                                            class="block w-full text-left px-3 py-1.5 border-b border-zinc-800/60 hover:bg-zinc-900 focus:outline-none {{ $detailId === $row->id && $detailSource === $row->source ? 'bg-zinc-900' : '' }}">
                                        <div class="flex items-center justify-between gap-2 text-[10px] font-mono">
                                            <span class="inline-flex items-center gap-1.5">
                                                <span class="rounded px-1.5 py-0.5 ring-1 {{ $statusBadge($row->status) }}">
                                                    {{ strtoupper($row->status) }}
                                                </span>
                                                @if ($row->exitCode !== null)
                                                    <span class="text-zinc-500">exit {{ $row->exitCode }}</span>
                                                @endif
                                                @if ($row->durationSeconds !== null)
                                                    <span class="text-zinc-500">· {{ $row->durationSeconds }}s</span>
                                                @endif
                                                <span class="text-zinc-600">· {{ $row->source }}</span>
                                            </span>
                                            <span class="text-zinc-500 whitespace-nowrap">{{ optional($row->startedAt ?? $row->createdAt)?->diffForHumans() ?? '—' }}</span>
                                        </div>
                                        <div class="mt-0.5 truncate font-mono text-[11px] text-zinc-200">{{ $row->commandPreview ?: $row->label }}</div>
                                    </button>
                                @empty
                                    <p class="p-3 text-[11px] text-zinc-500">
                                        {{ __('No process runs recorded for this organization yet.') }}
                                    </p>
                                @endforelse
                            @endif
                        </div>

                        {{-- DETAIL --}}
                        <div class="w-1/2 min-w-0 overflow-y-auto border-l border-zinc-800 bg-black">
                            @if ($detailSource && $detailId)
                                <div class="flex items-center justify-between px-3 py-1.5 text-[10px] uppercase tracking-wide text-zinc-400 border-b border-zinc-800">
                                    <span class="font-mono">{{ $detailSource }} · {{ \Illuminate\Support\Str::limit($detailId, 18, '…') }}</span>
                                    <button type="button" wire:click="clearDetail" class="hover:text-zinc-200">{{ __('close') }}</button>
                                </div>
                                @if ($detailOutput === null || trim($detailOutput) === '')
                                    <p class="p-3 text-[11px] text-zinc-500">{{ __('(no output captured)') }}</p>
                                @else
                                    <pre class="p-3 whitespace-pre-wrap break-all font-mono text-[11px] leading-snug text-emerald-200">{{ $detailOutput }}</pre>
                                @endif
                            @else
                                <p class="p-3 text-[11px] text-zinc-500">{{ __('Select a row to view full output.') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @push('scripts')
            <script>
                window.dplyTaskRunnerDebugPanel = function (initial) {
                    return {
                        organizationId: initial.organizationId ?? null,
                        expanded: !!initial.expanded,
                        liveBuffer: [],
                        liveCount: 0,
                        bound: false,
                        init() {
                            if (!this.organizationId) return;
                            const bind = () => this.bindEcho();
                            bind();
                            document.addEventListener('dply:echo-ready', bind);
                            document.addEventListener('livewire:navigated', bind);
                        },
                        bindEcho() {
                            if (this.bound || !window.Echo) return;
                            try {
                                const ch = window.Echo.private('organization.' + this.organizationId);
                                ch.listen('.debug.task-runner.activity', (e) => {
                                    const payload = e.payload || {};
                                    const preview = payload.chunk
                                        ?? payload.message
                                        ?? payload.command
                                        ?? '';
                                    const row = {
                                        uid: (e.kind ?? '') + ':' + (e.at ?? '') + ':' + Math.random().toString(36).slice(2, 8),
                                        kind: e.kind ?? 'unknown',
                                        preview: String(preview).slice(0, 600),
                                        at: e.at ?? '',
                                    };
                                    this.liveBuffer.unshift(row);
                                    if (this.liveBuffer.length > 200) this.liveBuffer.length = 200;
                                    if (e.kind === 'task.started') this.liveCount++;
                                    if (e.kind === 'task.completed' || e.kind === 'task.error') {
                                        this.liveCount = Math.max(0, this.liveCount - 1);
                                    }
                                    if (window.Livewire) {
                                        window.Livewire.dispatch('debug-task-runner-activity', { kind: e.kind });
                                    }
                                });
                                this.bound = true;
                            } catch (err) {
                                // Echo not yet ready or org channel auth failed —
                                // both are fine; the Recent tab still works DB-side.
                                console.debug('TaskRunner debug panel: Echo bind failed', err);
                            }
                        },
                    };
                };
            </script>
        @endpush
    @endif
</div>
