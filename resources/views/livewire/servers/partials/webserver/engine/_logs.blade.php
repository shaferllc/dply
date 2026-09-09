            {{-- =============================================================
                 LOGS — last N lines of access / error / journal for the active
                 engine. Live toggle wires a poll so the buffer refreshes every
                 4s while the operator watches a request flow through.
                 ============================================================= --}}
            @if ((($optimisticEngineSubtabs ?? false) || $engine_subtab === 'logs') && $isActive && $engineHasFullControls($key))
                <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'logs'" x-cloak @endif>
                @php
                    $layout = $webserverConfigLayout[$key] ?? [];
                    $hasAccessLog = ! empty($layout['access_log']);
                    $hasErrorLog = ! empty($layout['error_log']);
                    $hasJournal = ! empty($layout['journal_unit']);
                @endphp
                <div class="{{ $card }}">
                    @if ($log_live)
                        <div wire:poll.4s="refreshWebserverLog" class="hidden" aria-hidden="true"></div>
                    @endif

                    {{-- Dense head; the Live state rides the count pill so it
                         reads at a glance without hunting for the toggle. --}}
                    <x-workspace-panel-head
                        dense
                        icon="heroicon-o-document-text"
                        :title="__(':engine logs', ['engine' => $info['label']])"
                        :count="$log_live ? __('Live') : null"
                        :note="__('Tail the last N lines of the access / error log. Toggle Live to poll every 4 s.')"
                        class="border-b border-brand-ink/10"
                    />

                    @if (! $opsReady || $isDeployer)
                        {{-- Same gate as the Config sub-tab — name the one blocker
                             that actually applies rather than listing both. --}}
                        <div class="px-4 py-3.5 sm:px-5">
                            <x-empty-state
                                compact
                                tone="amber"
                                icon="heroicon-o-lock-closed"
                                :title="$isDeployer ? __('Read-only for your role') : __('Server isn\'t ready for ops')"
                                :description="$isDeployer
                                    ? __('Deploy-only members can view :engine but can\'t tail its logs. Ask an owner or admin to change your role.', ['engine' => $info['label']])
                                    : __('dply needs a ready ops connection to this server before it can tail :engine logs. Check the server\'s connection status, then reopen this tab.', ['engine' => $info['label']])"
                            />
                        </div>
                    @else
                        @php $accessLog = $log_kind === 'access' ? $this->parsedAccessLog : ['structured' => false]; @endphp
                        {{-- Toolbar: source picker as a segmented control, then the
                             actions, with the line budget pinned right. --}}
                        <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-4 py-2 sm:px-5">
                            @php
                                $logKinds = array_filter([
                                    $hasAccessLog ? ['key' => 'access', 'label' => __('Access')] : null,
                                    $hasErrorLog ? ['key' => 'error', 'label' => __('Error')] : null,
                                    $hasJournal ? ['key' => 'journal', 'label' => __('journalctl')] : null,
                                ]);
                            @endphp
                            @if ($logKinds !== [])
                                <div class="inline-flex items-center gap-0.5 rounded-lg border border-brand-ink/10 bg-white p-0.5 shadow-sm" role="group" aria-label="{{ __('Log source') }}">
                                    @foreach ($logKinds as $logKind)
                                        <button type="button" wire:click="refreshWebserverLog('{{ $logKind['key'] }}')" @class([
                                            'inline-flex h-6 items-center rounded-md px-2 text-xs font-semibold transition',
                                            'bg-brand-ink text-brand-cream shadow-sm' => $log_kind === $logKind['key'],
                                            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $log_kind !== $logKind['key'],
                                        ])>{{ $logKind['label'] }}</button>
                                    @endforeach
                                </div>
                            @endif

                            <button type="button" wire:click="refreshWebserverLog" wire:loading.attr="disabled" wire:target="refreshWebserverLog" class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-60">
                                <span wire:loading.remove wire:target="refreshWebserverLog" class="inline-flex">
                                    <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                </span>
                                <span wire:loading wire:target="refreshWebserverLog" class="inline-flex">
                                    <x-spinner class="h-3.5 w-3.5" />
                                </span>
                                {{ __('Refresh') }}
                            </button>
                            <button type="button" wire:click="toggleWebserverLogLive" @class([
                                'inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border px-2 text-xs font-semibold shadow-sm transition',
                                'border-emerald-300 bg-emerald-50 text-emerald-900' => $log_live,
                                'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $log_live,
                            ])>
                                @if ($log_live)
                                    <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-600" aria-hidden="true"></span>
                                @else
                                    <x-heroicon-m-play class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                @endif
                                {{ __('Live') }}
                            </button>
                            @if ($log_kind === 'access' && ($accessLog['structured'] || $log_raw) && $log_output !== '')
                                <button type="button" wire:click="toggleWebserverLogRaw" @class([
                                    'inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border px-2 text-xs font-semibold shadow-sm transition',
                                    'border-brand-ink bg-brand-ink text-brand-cream' => $log_raw,
                                    'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $log_raw,
                                ])>
                                    <x-heroicon-m-code-bracket class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Raw') }}
                                </button>
                            @endif
                            <span class="ml-auto inline-flex items-center gap-1 text-xs text-brand-moss">
                                {{ __('Lines:') }}
                                <select wire:change="refreshWebserverLog(null, $event.target.value)" class="h-6 rounded-md border border-brand-ink/15 bg-white py-0 pl-2 pr-7 text-xs font-semibold text-brand-ink">
                                    @foreach ([100, 300, 500, 1000, 2000] as $n)
                                        <option value="{{ $n }}" @selected($log_lines === $n)>{{ $n }}</option>
                                    @endforeach
                                </select>
                            </span>
                        </div>

                        @if ($log_kind === 'access' && ! $log_raw && ($accessLog['structured'] ?? false))
                            @php
                                $summary = $accessLog['summary'];
                                $statusMeta = [
                                    '2xx' => ['label' => __('2xx'), 'dot' => 'bg-emerald-500', 'text' => 'text-emerald-700', 'ring' => 'ring-emerald-500/20', 'bg' => 'bg-emerald-50'],
                                    '3xx' => ['label' => __('3xx'), 'dot' => 'bg-sky-500', 'text' => 'text-sky-700', 'ring' => 'ring-sky-500/20', 'bg' => 'bg-sky-50'],
                                    '4xx' => ['label' => __('4xx'), 'dot' => 'bg-amber-500', 'text' => 'text-amber-700', 'ring' => 'ring-amber-500/20', 'bg' => 'bg-amber-50'],
                                    '5xx' => ['label' => __('5xx'), 'dot' => 'bg-red-500', 'text' => 'text-red-700', 'ring' => 'ring-red-500/20', 'bg' => 'bg-red-50'],
                                    'other' => ['label' => __('Other'), 'dot' => 'bg-brand-mist', 'text' => 'text-brand-moss', 'ring' => 'ring-brand-ink/10', 'bg' => 'bg-brand-sand/50'],
                                ];
                                // Pre-shape rows for the client so Alpine can filter without another round-trip.
                                $clientRows = array_map(function ($r) {
                                    if (! ($r['parsed'] ?? false)) {
                                        return ['parsed' => false, 'raw' => $r['raw'] ?? ''];
                                    }
                                    $t = $r['time'] ?? null;
                                    return [
                                        'parsed' => true,
                                        'ip' => $r['ip'],
                                        'iso' => $t?->toIso8601String(),
                                        'rel' => $t?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW, true),
                                        'abs' => $t?->toDayDateTimeString() ?? ($r['time_raw'] ?? null),
                                        'method' => $r['method'],
                                        'path' => $r['path'],
                                        'protocol' => $r['protocol'],
                                        'status' => $r['status'],
                                        'statusClass' => app(\App\Services\Servers\NginxAccessLogParser::class)->statusClass($r['status']),
                                        'bytes' => $r['bytes'],
                                        'bytesHuman' => $r['bytes'] !== null ? \Illuminate\Support\Number::fileSize($r['bytes']) : '—',
                                        'referer' => $r['referer'],
                                        'user_agent' => $r['user_agent'],
                                    ];
                                }, $accessLog['rows']);
                            @endphp

                            <div class="px-4 py-3.5 sm:px-5" x-data="{
                                q: '',
                                rows: @js($clientRows),
                                statusStyle(c) {
                                    return ({
                                        '2xx': 'bg-emerald-50 text-emerald-700 ring-emerald-500/25',
                                        '3xx': 'bg-sky-50 text-sky-700 ring-sky-500/25',
                                        '4xx': 'bg-amber-50 text-amber-700 ring-amber-500/25',
                                        '5xx': 'bg-red-50 text-red-700 ring-red-500/25',
                                    })[c] || 'bg-brand-sand/60 text-brand-moss ring-brand-ink/10';
                                },
                                matches(r) {
                                    if (this.q.trim() === '') return true;
                                    const q = this.q.toLowerCase();
                                    if (!r.parsed) return (r.raw || '').toLowerCase().includes(q);
                                    return [r.path, r.method, String(r.status), r.ip, r.user_agent]
                                        .filter(Boolean).some(v => String(v).toLowerCase().includes(q));
                                },
                                get visible() { return this.rows.filter(r => this.matches(r)); },
                            }">
                                {{-- Summary header --}}
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sage/15 px-3 py-1 text-xs font-semibold text-brand-forest ring-1 ring-brand-sage/25">
                                        {{ trans_choice(':count request|:count requests', $summary['total'], ['count' => $summary['total']]) }}
                                    </span>
                                    @foreach (['2xx', '3xx', '4xx', '5xx'] as $cls)
                                        @if (($summary['classes'][$cls] ?? 0) > 0)
                                            @php $m = $statusMeta[$cls]; @endphp
                                            <span class="inline-flex items-center gap-1.5 rounded-full {{ $m['bg'] }} px-2.5 py-1 text-xs font-medium {{ $m['text'] }} ring-1 {{ $m['ring'] }}">
                                                <span class="inline-block h-1.5 w-1.5 rounded-full {{ $m['dot'] }}" aria-hidden="true"></span>
                                                {{ $m['label'] }} · {{ $summary['classes'][$cls] }}
                                            </span>
                                        @endif
                                    @endforeach
                                    @if (! empty($summary['top_paths']))
                                        <span class="mx-1 hidden h-4 w-px bg-brand-ink/10 sm:inline-block" aria-hidden="true"></span>
                                        <span class="text-xs text-brand-moss">{{ __('Top:') }}</span>
                                        @foreach ($summary['top_paths'] as $tp)
                                            <span class="inline-flex max-w-[14rem] items-center gap-1 truncate rounded-md bg-brand-sand/50 px-2 py-1 font-mono text-xs text-brand-ink ring-1 ring-brand-ink/10" title="{{ $tp['path'] }} ({{ $tp['count'] }})">
                                                <span class="truncate">{{ $tp['path'] }}</span>
                                                <span class="text-brand-mist">×{{ $tp['count'] }}</span>
                                            </span>
                                        @endforeach
                                    @endif

                                    <div class="relative ml-auto">
                                        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-brand-mist" />
                                        <input type="text" x-model="q" placeholder="{{ __('Filter path / status / method…') }}"
                                            class="w-56 rounded-md border border-brand-ink/15 bg-white py-1.5 pl-8 pr-3 text-xs text-brand-ink placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage/30" />
                                    </div>
                                </div>

                                {{-- Structured table --}}
                                <div class="mt-3 max-h-[60vh] overflow-auto rounded-lg border border-brand-ink/10 bg-white">
                                    <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                                        <thead class="sticky top-0 z-10 bg-brand-sand/60 text-2xs font-semibold uppercase tracking-wider text-brand-moss">
                                            <tr>
                                                <th class="px-3 py-2">{{ __('When') }}</th>
                                                <th class="px-3 py-2">{{ __('Status') }}</th>
                                                <th class="px-3 py-2">{{ __('Method') }}</th>
                                                <th class="px-3 py-2">{{ __('Path') }}</th>
                                                <th class="px-3 py-2 text-right">{{ __('Bytes') }}</th>
                                                <th class="px-3 py-2">{{ __('Client') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-brand-ink/5">
                                            <template x-for="(r, i) in visible" :key="i">
                                                <tr class="align-top hover:bg-brand-sand/30">
                                                    <template x-if="!r.parsed">
                                                        <td colspan="6" class="px-3 py-1.5 font-mono text-xs text-brand-moss break-all" x-text="r.raw"></td>
                                                    </template>
                                                    <template x-if="r.parsed">
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-brand-moss">
                                                            <span :title="r.abs" x-text="r.rel ? (r.rel + ' {{ __('ago') }}') : (r.abs || '—')"></span>
                                                        </td>
                                                    </template>
                                                    <template x-if="r.parsed">
                                                        <td class="px-3 py-1.5">
                                                            <span class="inline-flex items-center rounded-md px-1.5 py-0.5 font-mono text-xs font-semibold ring-1 ring-inset"
                                                                :class="statusStyle(r.statusClass)" x-text="r.status ?? '—'"></span>
                                                        </td>
                                                    </template>
                                                    <template x-if="r.parsed">
                                                        <td class="px-3 py-1.5">
                                                            <span class="inline-flex items-center rounded bg-brand-ink/5 px-1.5 py-0.5 font-mono text-2xs font-semibold text-brand-ink" x-text="r.method || '—'"></span>
                                                        </td>
                                                    </template>
                                                    <template x-if="r.parsed">
                                                        <td class="px-3 py-1.5">
                                                            <span class="block max-w-[28rem] truncate font-mono text-xs text-brand-ink" :title="r.path" x-text="r.path || '—'"></span>
                                                            <span class="text-2xs text-brand-mist" x-text="r.protocol"></span>
                                                        </td>
                                                    </template>
                                                    <template x-if="r.parsed">
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-right font-mono text-xs text-brand-moss" x-text="r.bytesHuman"></td>
                                                    </template>
                                                    <template x-if="r.parsed">
                                                        <td class="px-3 py-1.5">
                                                            <span class="block font-mono text-xs text-brand-ink" x-text="r.ip || '—'"></span>
                                                            <span class="block max-w-[18rem] truncate text-2xs text-brand-mist" :title="r.user_agent" x-text="r.user_agent || ''"></span>
                                                        </td>
                                                    </template>
                                                </tr>
                                            </template>
                                            <tr x-show="visible.length === 0">
                                                <td colspan="6" class="px-3 py-6 text-center text-xs text-brand-mist">{{ __('No entries match your filter.') }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @else
                            {{-- Full-bleed pane: the terminal runs edge to edge like
                                 a real tail, instead of floating as an inset card
                                 inside an already-bordered panel. Squared off and
                                 hairlined at the top so it reads as the panel body. --}}
                            <pre class="max-h-[60vh] overflow-auto whitespace-pre-wrap break-all border-t border-brand-ink/10 bg-brand-ink/95 px-4 py-3 font-mono text-xs leading-relaxed text-emerald-100 sm:px-5" x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">{{ $log_output !== '' ? $log_output : __('Click Refresh (or toggle Live) to fetch the log.') }}</pre>
                        @endif
                    @endif
                </div>
                </div>
            @endif
