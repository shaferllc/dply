    {{-- Fix connectivity — for an UNREACHABLE resource binding: open access +
         firewall this app server's /32 + re-probe, or re-point at the right
         backend if it's aimed at a server that doesn't serve the resource. --}}
    @if (method_exists($this, 'fixBindingConnectivity'))
    <x-modal name="fix-binding-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
        <div class="px-6 py-5">
            @if ($fixBindingRunId)
                {{-- Progress view: the fix is running — stream its console output
                     in place. The banner partial polls itself until terminal. --}}
                @php $fixRun = \App\Models\ConsoleAction::find($fixBindingRunId); @endphp
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Fixing connectivity') }}</h3>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Opening remote access + firewall on the backend, then re-probing. Live log below — you can close this; it keeps running.') }}</p>
                <div class="mt-4">
                    @if ($fixRun)
                        @include('livewire.partials.console-action-banner-static', [
                            'run' => $fixRun,
                            'kindLabels' => (array) config('console_actions.kinds', []),
                        ])
                    @else
                        <p class="text-sm text-brand-moss">{{ __('Starting…') }}</p>
                    @endif
                </div>
            @elseif ($fixBindingId)
                @php $fixCands = $this->bindingFixCandidates($fixBindingId); @endphp
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Fix connectivity') }}</h3>
                <p class="mt-1 text-xs text-brand-moss">{{ __('dply will enable remote access on the backend, open its firewall for this app server’s private address, and re-test. If it’s pointing at the wrong server, re-point below.') }}</p>
                <div class="mt-4 space-y-3">
                    <button type="button" wire:click="fixBindingConnectivity(@js($fixBindingId))" wire:loading.attr="disabled" class="w-full rounded-lg bg-brand-ink px-3 py-2 text-left text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-60">
                        {{ __('Fix in place (keep current target)') }}
                        <span class="mt-0.5 block text-[11px] font-normal text-brand-cream/70">{{ __('Open remote access + firewall this app server, then re-probe.') }}</span>
                    </button>
                    @if (! empty($fixCands))
                        <div>
                            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Wrong server? Re-point to:') }}</p>
                            <div class="space-y-1">
                                @foreach ($fixCands as $c)
                                    <button type="button" wire:click="fixBindingConnectivity(@js($fixBindingId), '{{ $c['id'] }}')" wire:loading.attr="disabled" class="flex w-full items-center justify-between gap-2 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-left hover:bg-brand-sand/40 disabled:opacity-60">
                                        <span class="min-w-0 truncate text-sm text-brand-ink">{{ $c['label'] }} <span class="text-xs text-brand-mist">{{ $c['server'] ? '· '.$c['server'] : '' }}</span></span>
                                        <span class="shrink-0 font-mono text-[10px] text-brand-mist">{{ $c['host'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @else
                {{-- Loading window: the modal opens instantly via Alpine while
                     `fixBindingId` is still being set over the wire. Show a
                     skeleton so it never flashes empty. --}}
                <div class="flex items-center gap-3 py-2" wire:key="fix-binding-loading">
                    <svg class="h-5 w-5 shrink-0 animate-spin text-brand-forest" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <div class="min-w-0">
                        <p class="text-base font-semibold text-brand-ink">{{ __('Fix connectivity') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Preparing fix options…') }}</p>
                    </div>
                </div>
            @endif
            <div class="mt-4 flex justify-end border-t border-brand-ink/10 pt-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ $fixBindingRunId ? __('Close') : __('Cancel') }}</x-secondary-button>
            </div>
        </div>
    </x-modal>
    @endif
