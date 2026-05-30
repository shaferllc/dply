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
                <div class="{{ $card }} p-6 sm:p-8">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="max-w-2xl">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine logs', ['engine' => $info['label']]) }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Tail the last N lines of the access / error log. Toggle Live to poll every 4 s.') }}</p>
                        </div>
                        @if ($log_live)
                            <div wire:poll.4s="refreshWebserverLog" class="hidden" aria-hidden="true"></div>
                        @endif
                    </div>

                    @if (! $opsReady || $isDeployer)
                        <p class="mt-4 text-sm text-brand-moss">{{ __('Logs require ready ops access and a non-deployer role.') }}</p>
                    @else
                        <div class="mt-5 flex flex-wrap items-center gap-2">
                            @if ($hasAccessLog)
                                <button type="button" wire:click="refreshWebserverLog('access')" @class([
                                    'rounded-md border px-3 py-1.5 text-xs font-medium',
                                    'border-brand-forest bg-brand-forest text-brand-cream' => $log_kind === 'access',
                                    'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $log_kind !== 'access',
                                ])>{{ __('Access') }}</button>
                            @endif
                            @if ($hasErrorLog)
                                <button type="button" wire:click="refreshWebserverLog('error')" @class([
                                    'rounded-md border px-3 py-1.5 text-xs font-medium',
                                    'border-brand-forest bg-brand-forest text-brand-cream' => $log_kind === 'error',
                                    'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $log_kind !== 'error',
                                ])>{{ __('Error') }}</button>
                            @endif
                            @if ($hasJournal)
                                <button type="button" wire:click="refreshWebserverLog('journal')" @class([
                                    'rounded-md border px-3 py-1.5 text-xs font-medium',
                                    'border-brand-forest bg-brand-forest text-brand-cream' => $log_kind === 'journal',
                                    'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $log_kind !== 'journal',
                                ])>{{ __('journalctl') }}</button>
                            @endif

                            <span class="mx-2 hidden h-5 w-px bg-brand-ink/10 sm:inline-block" aria-hidden="true"></span>

                            <button type="button" wire:click="refreshWebserverLog" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                {{ __('Refresh') }}
                            </button>
                            <button type="button" wire:click="toggleWebserverLogLive" @class([
                                'inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-xs font-medium',
                                'border-emerald-300 bg-emerald-50 text-emerald-900' => $log_live,
                                'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $log_live,
                            ])>
                                @if ($log_live)
                                    <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-600" aria-hidden="true"></span>
                                    {{ __('Live') }}
                                @else
                                    <x-heroicon-o-play class="h-3.5 w-3.5" />
                                    {{ __('Live') }}
                                @endif
                            </button>
                            <span class="ml-auto inline-flex items-center gap-1 text-[11px] text-brand-moss">
                                {{ __('Lines:') }}
                                <select wire:change="refreshWebserverLog(null, $event.target.value)" class="rounded-md border border-brand-ink/15 bg-white py-0.5 pl-2 pr-7 text-[11px] font-medium text-brand-ink">
                                    @foreach ([100, 300, 500, 1000, 2000] as $n)
                                        <option value="{{ $n }}" @selected($log_lines === $n)>{{ $n }}</option>
                                    @endforeach
                                </select>
                            </span>
                        </div>

                        <pre class="mt-4 max-h-[60vh] overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-4 font-mono text-xs leading-relaxed text-emerald-100" x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">{{ $log_output !== '' ? $log_output : __('Click Refresh (or toggle Live) to fetch the log.') }}</pre>
                    @endif
                </div>
                </div>
            @endif
