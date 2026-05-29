@if ($logsModalContainerId)
    <div class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain" role="dialog" aria-modal="true" aria-labelledby="docker-logs-title" wire:key="docker-logs-modal">
        <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeContainerLogsModal"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6">
            <div class="my-auto flex w-full max-w-4xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                <div class="flex shrink-0 items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Container logs') }}</p>
                        <h2 id="docker-logs-title" class="mt-1 font-mono text-sm font-semibold text-brand-ink">{{ $logsModalContainerName }}</h2>
                    </div>
                    <button type="button" wire:click="closeContainerLogsModal" class="rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>
                <div class="max-h-[28rem] overflow-auto bg-brand-ink px-4 py-4">
                    @if ($logsModalLoading)
                        <div class="flex items-center gap-2 text-sm text-brand-cream/80">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Loading logs…') }}
                        </div>
                    @elseif ($logsModalError)
                        <p class="text-sm text-rose-300">{{ $logsModalError }}</p>
                    @else
                        <pre class="whitespace-pre-wrap break-all font-mono text-xs leading-relaxed text-brand-cream/95">{{ $logsModalContent !== '' ? $logsModalContent : __('No log output.') }}</pre>
                    @endif
                </div>
                <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeContainerLogsModal">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </div>
    </div>
@endif

@if ($inspectModalContainerId)
    <div class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain" role="dialog" aria-modal="true" aria-labelledby="docker-inspect-title" wire:key="docker-inspect-modal">
        <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeContainerInspectModal"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6">
            <div class="my-auto flex w-full max-w-4xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                <div class="flex shrink-0 items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Container inspect') }}</p>
                        <h2 id="docker-inspect-title" class="mt-1 font-mono text-sm font-semibold text-brand-ink">{{ $inspectModalContainerName }}</h2>
                    </div>
                    <button type="button" wire:click="closeContainerInspectModal" class="rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>
                <div class="max-h-[28rem] overflow-auto bg-brand-ink px-4 py-4">
                    @if ($inspectModalLoading)
                        <div class="flex items-center gap-2 text-sm text-brand-cream/80">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Loading inspect JSON…') }}
                        </div>
                    @elseif ($inspectModalError)
                        <p class="text-sm text-rose-300">{{ $inspectModalError }}</p>
                    @else
                        <pre class="whitespace-pre-wrap break-all font-mono text-xs leading-relaxed text-brand-cream/95">{{ $inspectModalContent }}</pre>
                    @endif
                </div>
                <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeContainerInspectModal">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </div>
    </div>
@endif
