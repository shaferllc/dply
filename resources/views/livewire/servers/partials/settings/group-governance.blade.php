<section id="settings-group-governance" aria-labelledby="settings-group-governance-title">
    @if (! empty($costReport))
        @include('livewire.servers.partials.settings.cost-card-estimate', [
            'card' => $card,
            'server' => $server,
            'report' => $costReport,
        ])
    @endif

    <div id="settings-cost" class="{{ $card }} scroll-mt-24">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-currency-dollar"
            :title="__('Your cost note')"
            :note="__('What you record for this server’s provider cost. Pull the catalog price when supported, or type a negotiated / chargeback figure.')"
            title-id="settings-group-governance-title"
            class="border-b border-brand-ink/10"
        />

        <div class="px-5 py-4 sm:px-6">
            @php $costPullSupported = $this->providerCostPullSupported(); @endphp
            <form wire:submit="saveCostLifecycle" class="space-y-3">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="min-w-0 flex-1">
                        <x-input-label for="settings-cost-note" value="{{ __('Monthly cost note') }}" />
                        <input
                            id="settings-cost-note"
                            type="text"
                            wire:model="settingsCostMonthlyNote"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 disabled:cursor-not-allowed disabled:opacity-60"
                            placeholder="{{ __('e.g. ~$48/mo on annual commit') }}"
                            @if (! $this->canEditServerSettings) disabled @endif
                        />
                    </div>
                    @if ($this->canEditServerSettings)
                        {{-- Blade @if/@disabled cannot sit on <x-*> attribute lists (Livewire → Alpine). --}}
                        @if ($costPullSupported)
                            <x-secondary-button
                                type="button"
                                size="xs"
                                class="shrink-0"
                                wire:click="pullCostFromProvider"
                                wire:loading.attr="disabled"
                                wire:target="pullCostFromProvider"
                                title="{{ __('Fetch the current catalog price for this server\'s plan from the provider. The value lands in the box; nothing is saved until you use the save bar.') }}"
                            >
                                <svg class="h-4 w-4" wire:loading.remove wire:target="pullCostFromProvider" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M4 4v5h5" />
                                    <path d="M16 16v-5h-5" />
                                    <path d="M5.5 9a6 6 0 0 1 10.4-2.5" />
                                    <path d="M14.5 11a6 6 0 0 1-10.4 2.5" />
                                </svg>
                                <svg class="h-4 w-4 animate-spin" wire:loading wire:target="pullCostFromProvider" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/>
                                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                                </svg>
                                <span wire:loading.remove wire:target="pullCostFromProvider">{{ __('Pull from provider') }}</span>
                                <span wire:loading wire:target="pullCostFromProvider">{{ __('Pulling…') }}</span>
                            </x-secondary-button>
                        @else
                            <x-secondary-button
                                type="button"
                                size="xs"
                                class="shrink-0"
                                disabled
                                title="{{ __('Pulling cost from this provider is not yet supported, or this server has no linked credential / size on file.') }}"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M4 4v5h5" />
                                    <path d="M16 16v-5h-5" />
                                    <path d="M5.5 9a6 6 0 0 1 10.4-2.5" />
                                    <path d="M14.5 11a6 6 0 0 1-10.4 2.5" />
                                </svg>
                                {{ __('Pull from provider') }}
                            </x-secondary-button>
                        @endif
                    @endif
                </div>

                @if ($lastPulledCostEstimate)
                    <p class="text-xs text-brand-moss">
                        {{ __(':provider :plan · :currency :amount/mo · pulled :fetched', [
                            'provider' => $lastPulledCostEstimate['provider_label'],
                            'plan' => $lastPulledCostEstimate['plan'],
                            'currency' => $lastPulledCostEstimate['currency'],
                            'amount' => number_format((float) $lastPulledCostEstimate['monthly'], 2),
                            'fetched' => \Illuminate\Support\Carbon::parse($lastPulledCostEstimate['fetched_at'])->toFormattedDateString(),
                        ]) }}
                    </p>
                @else
                    <p class="text-xs text-brand-mist">
                        {{ __('Catalog price only — excludes taxes, transfer, snapshots, and volumes.') }}
                    </p>
                @endif

                <x-input-error :messages="$errors->get('settingsCostMonthlyNote')" class="mt-1" />
            </form>
        </div>
    </div>
</section>
