<section id="settings-group-governance" class="space-y-6" aria-labelledby="settings-group-governance-title">
    @if (! empty($costReport))
        @include('livewire.servers.partials.settings.cost-card-estimate', [
            'card' => $card,
            'server' => $server,
            'report' => $costReport,
        ])
    @endif

    <div id="settings-cost" class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-currency-dollar class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Cost') }}</p>
                <h2 id="settings-group-governance-title" class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cost') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Stack estimates and finance notes for your team. Estimates are not invoiced amounts — save a cost note below to improve provider lines.') }}</p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Cost & lifecycle') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Rough costs and renewal reminders for your team. Pull the catalog price from your provider when supported, or type your own number — for example a negotiated annual commit, a parent-account sub-allocation, or a chargeback total that includes data transfer.') }}
        </p>
        <form wire:submit="saveCostLifecycle" class="mt-6 grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <div class="flex items-end justify-between gap-3">
                    <x-input-label for="settings-cost-note" value="{{ __('Monthly cost note') }}" />
                    @if ($this->canEditServerSettings)
                        @php $costPullSupported = $this->providerCostPullSupported(); @endphp
                        <button
                            type="button"
                            wire:click="pullCostFromProvider"
                            wire:loading.attr="disabled"
                            wire:target="pullCostFromProvider"
                            @disabled(! $costPullSupported)
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            title="{{ $costPullSupported
                                ? __('Fetch the current catalog price for this server\'s plan from the provider. The value lands in the box below; nothing is saved until you click Save cost notes.')
                                : __('Pulling cost from this provider is not yet supported, or this server has no linked credential / size on file.') }}"
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
                        </button>
                    @endif
                </div>
                <input
                    id="settings-cost-note"
                    type="text"
                    wire:model="settingsCostMonthlyNote"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="{{ __('e.g. ~$48/mo on annual commit') }}"
                    @disabled(! $this->canEditServerSettings)
                />
                @if ($lastPulledCostEstimate)
                    <p class="mt-1.5 text-xs text-brand-moss">
                        {{ __('Pulled :currency :amount/mo for plan :plan from :provider on :fetched. Edit the field above to override before saving.', [
                            'currency' => $lastPulledCostEstimate['currency'],
                            'amount' => number_format((float) $lastPulledCostEstimate['monthly'], 2),
                            'plan' => $lastPulledCostEstimate['plan'],
                            'provider' => $lastPulledCostEstimate['provider_label'],
                            'fetched' => \Illuminate\Support\Carbon::parse($lastPulledCostEstimate['fetched_at'])->toDayDateTimeString(),
                        ]) }}
                    </p>
                    <dl class="mt-3 grid gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Catalog rate') }}</dt>
                            <dd class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ $lastPulledCostEstimate['currency'] === 'USD' ? '$' : '' }}{{ number_format((float) $lastPulledCostEstimate['monthly'], 2) }}<span class="text-xs font-normal text-brand-moss">/mo</span>
                            </dd>
                            <dd class="text-xs text-brand-moss">
                                {{ $lastPulledCostEstimate['currency'] === 'USD' ? '$' : '' }}{{ number_format((float) $lastPulledCostEstimate['hourly'], 4) }}/hr · {{ $lastPulledCostEstimate['currency'] }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Estimated MTD') }}</dt>
                            <dd class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ $lastPulledCostEstimate['currency'] === 'USD' ? '$' : '' }}{{ number_format((float) $lastPulledCostEstimate['mtd'], 2) }}
                            </dd>
                            <dd class="text-xs text-brand-moss">
                                {{ __(':hours hrs this month', ['hours' => number_format((float) $lastPulledCostEstimate['runtime_hours_month'], 1)]) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Estimated YTD') }}</dt>
                            <dd class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ $lastPulledCostEstimate['currency'] === 'USD' ? '$' : '' }}{{ number_format((float) $lastPulledCostEstimate['ytd'], 2) }}
                            </dd>
                            <dd class="text-xs text-brand-moss">
                                {{ __(':hours hrs this year', ['hours' => number_format((float) $lastPulledCostEstimate['runtime_hours_year'], 1)]) }}
                            </dd>
                        </div>
                        <div class="sm:col-span-3 text-xs text-brand-mist leading-relaxed">
                            {{ __('MTD and YTD are computed as catalog hourly rate × time this server has been alive in Dply. They exclude taxes, transfer overage, snapshots, volumes, and any negotiated discount. For the actual invoiced amount, open the provider console.') }}
                        </div>
                    </dl>
                @else
                    <p class="mt-1.5 text-xs text-brand-mist">
                        {{ __('Catalog price only — does not include taxes, data transfer overage, snapshots, or volume add-ons. Type your own value to record an actual or negotiated price. Pulling also computes runtime-based MTD and YTD estimates.') }}
                    </p>
                @endif
                <x-input-error :messages="$errors->get('settingsCostMonthlyNote')" class="mt-2" />
            </div>
            @if ($this->canEditServerSettings)
                <div class="sm:col-span-2 flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save cost notes') }}</x-primary-button>
                </div>
            @endif
        </form>
        </div>
    </div>
</section>
