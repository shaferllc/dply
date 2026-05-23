        @if ($showCacheStatusModal)
            <div
                class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center sm:p-6"
                role="dialog"
                aria-modal="true"
                aria-labelledby="cache-status-modal-heading"
            >
                <div class="fixed inset-0 bg-brand-ink/40 backdrop-blur-[1px]" wire:click="closeCacheStatusModal"></div>
                <div class="relative z-10 max-h-[min(92vh,52rem)] w-full max-w-[min(96vw,72rem)] overflow-y-auto overscroll-contain dply-modal-panel [-webkit-overflow-scrolling:touch]">
                    <div class="sticky top-0 z-[1] flex flex-col gap-3 border-b border-brand-ink/10 bg-white px-4 py-4 sm:px-6 sm:py-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10">
                                    @if ($cacheStatusModalView === 'logs')
                                        <x-heroicon-o-document-text class="h-4 w-4" aria-hidden="true" />
                                    @else
                                        <x-heroicon-o-information-circle class="h-4 w-4" aria-hidden="true" />
                                    @endif
                                </span>
                                <div class="min-w-0">
                                    <h2 id="cache-status-modal-heading" class="text-base font-semibold text-brand-ink">
                                        {{ $cacheStatusModalView === 'logs'
                                            ? __(':engine instance logs', ['engine' => $engineLabels[$cacheStatusModalEngine] ?? $cacheStatusModalEngine])
                                            : __(':engine instance status', ['engine' => $engineLabels[$cacheStatusModalEngine] ?? $cacheStatusModalEngine]) }}
                                    </h2>
                                    <p class="mt-0.5 font-mono text-xs text-brand-moss break-all">
                                        {{ $cacheStatusModalInstance }} · {{ $cacheStatusModalUnit }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                <button
                                    type="button"
                                    wire:click="refreshCacheStatusModal"
                                    wire:loading.attr="disabled"
                                    wire:target="refreshCacheStatusModal,setCacheStatusModalView"
                                    @disabled($cacheStatusModalLoading)
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-2 text-[11px] font-medium text-brand-moss hover:bg-brand-sand/40 disabled:opacity-50"
                                >
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0 text-brand-ink/80" wire:loading.class="animate-spin" wire:target="refreshCacheStatusModal,setCacheStatusModalView" />
                                    <span wire:loading.remove wire:target="refreshCacheStatusModal,setCacheStatusModalView">{{ __('Refresh') }}</span>
                                    <span wire:loading wire:target="refreshCacheStatusModal,setCacheStatusModalView">{{ __('Working…') }}</span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="closeCacheStatusModal"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-2 text-[11px] font-medium text-brand-moss hover:bg-brand-sand/40"
                                >
                                    {{ __('Close') }}
                                </button>
                            </div>
                        </div>
                        <div class="flex items-center gap-1 text-[11px] font-medium">
                            <button
                                type="button"
                                wire:click="setCacheStatusModalView('status')"
                                @disabled($cacheStatusModalLoading)
                                @class([
                                    'inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 disabled:opacity-50',
                                    'bg-brand-forest text-white' => $cacheStatusModalView === 'status',
                                    'bg-white text-brand-moss border border-brand-ink/15 hover:bg-brand-sand/40' => $cacheStatusModalView !== 'status',
                                ])
                            >
                                <x-heroicon-o-information-circle class="h-3 w-3" />
                                {{ __('Status') }}
                            </button>
                            <button
                                type="button"
                                wire:click="setCacheStatusModalView('logs')"
                                @disabled($cacheStatusModalLoading)
                                @class([
                                    'inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 disabled:opacity-50',
                                    'bg-brand-forest text-white' => $cacheStatusModalView === 'logs',
                                    'bg-white text-brand-moss border border-brand-ink/15 hover:bg-brand-sand/40' => $cacheStatusModalView !== 'logs',
                                ])
                            >
                                <x-heroicon-o-document-text class="h-3 w-3" />
                                {{ __('Logs') }}
                            </button>
                        </div>
                    </div>
                    <div class="px-4 py-4 sm:px-6 sm:py-5">
                        @if ($cacheStatusModalLoading)
                            <p class="text-xs font-medium text-brand-ink">
                                {{ $cacheStatusModalView === 'logs' ? __('Fetching journalctl logs…') : __('Fetching systemctl status…') }}
                            </p>
                            <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('This can take a few seconds over SSH.') }}</p>
                        @endif
                        @if ($cacheStatusModalError)
                            <div class="mb-3 rounded-lg border border-red-200/80 bg-red-50/90 px-3 py-2 text-xs text-red-900 whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $cacheStatusModalError }}</div>
                        @endif
                        @if ($cacheStatusModalOutput !== '')
                            <div class="rounded-xl border border-brand-ink/15 bg-zinc-50 p-3 shadow-inner">
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-ink">
                                        {{ $cacheStatusModalView === 'logs' ? __('journalctl -u') : __('systemctl status') }}
                                    </p>
                                </div>
                                <pre class="font-mono text-[11px] leading-snug whitespace-pre-wrap break-words text-zinc-900 [overflow-wrap:anywhere]">{{ $cacheStatusModalOutput }}</pre>
                            </div>
                        @elseif (! $cacheStatusModalLoading && $cacheStatusModalError === null)
                            <p class="text-xs text-brand-moss">{{ __('No output yet. Choose Refresh.') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif
