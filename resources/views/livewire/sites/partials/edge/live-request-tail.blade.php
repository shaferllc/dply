@php
    $tailSiteId = (string) $site->id;
@endphp

<section class="dply-card overflow-hidden"
         x-data="edgeLiveTail({{ Js::from(['siteId' => $tailSiteId, 'max' => 200]) }})"
         x-init="connect()"
         x-on:beforeunload.window="disconnect()">
    <div class="flex flex-wrap items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Live') }}</p>
            <h3 class="mt-0.5 inline-flex items-center gap-2 text-base font-semibold text-brand-ink">
                <span class="relative inline-flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 motion-safe:animate-ping"
                          x-show="status === 'connected'"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full"
                          :class="{ 'bg-emerald-500': status === 'connected', 'bg-amber-400': status === 'connecting', 'bg-rose-500': status === 'disconnected' }"></span>
                </span>
                {{ __('Live request tail') }}
            </h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                <span x-show="status === 'connected'">{{ __('Streaming Worker access logs in real time.') }}</span>
                <span x-show="status === 'connecting'" x-cloak>{{ __('Connecting to the broadcast channel…') }}</span>
                <span x-show="status === 'disconnected'" x-cloak>{{ __('Disconnected — refresh the page to reconnect.') }}</span>
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2 text-xs text-brand-moss">
            <input type="text"
                   x-model="filter"
                   placeholder="{{ __('Filter path / status / method') }}"
                   class="w-44 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
            <button type="button"
                    x-on:click="paused = ! paused"
                    class="rounded-lg border border-brand-ink/15 px-2 py-1 text-[11px] font-semibold transition"
                    :class="paused ? 'bg-amber-100 text-amber-900' : 'bg-white text-brand-moss hover:bg-brand-sand/40'">
                <span x-show="! paused">{{ __('Pause') }}</span>
                <span x-show="paused" x-cloak>{{ __('Resume') }}</span>
            </button>
            <button type="button"
                    x-on:click="rows = []; lastTickAt = null"
                    class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-moss hover:bg-brand-sand/40">
                {{ __('Clear') }}
            </button>
            <span class="font-mono text-[10px] text-brand-mist" x-text="rows.length + ' / {{ 200 }}'"></span>
        </div>
    </div>

    <div class="overflow-x-auto" style="max-height: 28rem;">
        <table class="min-w-full divide-y divide-brand-ink/8 text-sm">
            <thead class="sticky top-0 z-10 bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist backdrop-blur">
                <tr>
                    <th class="px-4 py-2">{{ __('Time') }}</th>
                    <th class="px-4 py-2">{{ __('Method') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('ms') }}</th>
                    <th class="px-4 py-2">{{ __('Cache') }}</th>
                    <th class="px-4 py-2">{{ __('Country') }}</th>
                    <th class="px-4 py-2">{{ __('Path') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                <template x-if="filteredRows.length === 0">
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-xs text-brand-moss">
                            <span x-show="status === 'connected' && rows.length === 0">{{ __('Waiting for the next request to hit your edge…') }}</span>
                            <span x-show="status === 'connecting'" x-cloak>{{ __('Connecting…') }}</span>
                            <span x-show="status === 'disconnected'" x-cloak>{{ __('No live stream.') }}</span>
                            <span x-show="rows.length > 0 && filteredRows.length === 0" x-cloak>{{ __('No rows match your filter.') }}</span>
                        </td>
                    </tr>
                </template>
                <template x-for="row in filteredRows" :key="row._id">
                    <tr :class="row._isNew ? 'bg-emerald-50/40 transition-colors duration-700 dark:bg-emerald-950/20' : ''">
                        <td class="px-4 py-1.5 font-mono text-[11px] text-brand-moss" x-text="row._timeLabel"></td>
                        <td class="px-4 py-1.5 font-mono text-[11px] font-semibold" x-text="row.method"></td>
                        <td class="px-4 py-1.5 text-right font-mono text-[11px] font-semibold"
                            :class="row.status >= 500 ? 'text-rose-700 dark:text-rose-400' : (row.status >= 400 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400')"
                            x-text="row.status || '—'"></td>
                        <td class="px-4 py-1.5 text-right font-mono text-[11px] text-brand-moss" x-text="row.duration_ms"></td>
                        <td class="px-4 py-1.5 font-mono text-[10px] uppercase text-brand-moss" x-text="row.cache_status || '—'"></td>
                        <td class="px-4 py-1.5 font-mono text-[11px] text-brand-moss" x-text="row.country || '—'"></td>
                        <td class="px-4 py-1.5 font-mono text-[11px] truncate max-w-[20rem]" :title="row.path" x-text="row.path"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</section>

@once
    @push('scripts')
        <script>
            window.edgeLiveTail = function (opts) {
                return {
                    siteId: opts.siteId,
                    max: opts.max || 200,
                    status: 'connecting',
                    rows: [],
                    filter: '',
                    paused: false,
                    lastTickAt: null,
                    _channel: null,
                    _newTimers: new Map(),

                    get filteredRows() {
                        if (! this.filter) return this.rows;
                        const needle = this.filter.toLowerCase();

                        return this.rows.filter((row) => {
                            return (row.path || '').toLowerCase().includes(needle)
                                || String(row.status || '').includes(needle)
                                || (row.method || '').toLowerCase().includes(needle);
                        });
                    },

                    connect() {
                        if (! window.Echo) {
                            this.status = 'disconnected';
                            console.warn('[edge-live-tail] window.Echo is not initialized.');

                            return;
                        }

                        try {
                            this._channel = window.Echo.private(`site.${this.siteId}`);
                            this._channel.listen('.edge.access-log', (payload) => this.onMessage(payload));
                            this.status = 'connected';
                        } catch (err) {
                            console.error('[edge-live-tail] subscribe failed', err);
                            this.status = 'disconnected';
                        }
                    },

                    disconnect() {
                        try {
                            if (window.Echo && this._channel) {
                                window.Echo.leave(`site.${this.siteId}`);
                            }
                        } catch (_err) {
                            // ignore — page is going away anyway
                        }
                    },

                    onMessage(payload) {
                        if (this.paused) return;

                        const row = {
                            _id: `${payload.occurred_at || Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
                            _timeLabel: this.formatTime(payload.occurred_at),
                            _isNew: true,
                            ...payload,
                        };
                        this.rows.unshift(row);
                        if (this.rows.length > this.max) {
                            this.rows.length = this.max;
                        }

                        const timer = setTimeout(() => {
                            row._isNew = false;
                            this._newTimers.delete(row._id);
                        }, 1500);
                        this._newTimers.set(row._id, timer);

                        this.lastTickAt = Date.now();
                    },

                    formatTime(iso) {
                        if (! iso) return '—';
                        try {
                            const d = new Date(iso);
                            return d.toLocaleTimeString([], { hour12: false }) + '.' + String(d.getMilliseconds()).padStart(3, '0');
                        } catch (_err) {
                            return iso;
                        }
                    },
                };
            };
        </script>
    @endpush
@endonce
