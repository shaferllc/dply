@php
    $breaches = $report['budget_breaches'] ?? [];
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
@endphp

<section id="budgets" class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-bell-alert class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Soft budgets') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Fairness thresholds per site') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Alert when a site exceeds its share of attributable CPU or memory. Routed through notification channels subscribed to Shared host alerts.') }}</p>
        </div>
    </div>

    @if ($breaches !== [])
        <div class="border-b border-amber-200 bg-amber-50/70 px-6 py-4 sm:px-7">
            <p class="text-sm font-semibold text-amber-900">{{ trans_choice(':count active budget breach|:count active budget breaches', count($breaches), ['count' => count($breaches)]) }}</p>
            <ul class="mt-2 space-y-1 text-sm text-amber-900/90">
                @foreach ($breaches as $breach)
                    <li>{{ $breach['message'] ?? '' }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($isDeployer)
        <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">
            {{ __('Deployers can view budgets but cannot change alert settings.') }}
        </div>
    @else
        <form wire:submit="saveSharedHostBudgets" class="space-y-5 px-6 py-5 sm:px-7">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="budgetAlertsEnabled" class="mt-1 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                <span>
                    <span class="block text-sm font-semibold text-brand-ink">{{ __('Send shared host alerts') }}</span>
                    <span class="mt-0.5 block text-sm text-brand-moss">{{ __('Uses the server.shared_host_alerts notification event.') }}</span>
                </span>
            </label>

            <div class="overflow-x-auto rounded-xl border border-brand-ink/10">
                <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                    <thead class="bg-brand-sand/30 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-sage">
                        <tr>
                            <th class="px-4 py-3">{{ __('Site') }}</th>
                            <th class="px-4 py-3">{{ __('Max CPU share %') }}</th>
                            <th class="px-4 py-3">{{ __('Max memory share %') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/10">
                        @foreach ($budgetSiteRows as $index => $row)
                            <tr wire:key="budget-row-{{ $row['slug'] ?? $index }}">
                                <td class="px-4 py-3 font-semibold text-brand-ink">{{ $row['name'] ?? $row['slug'] }}</td>
                                <td class="px-4 py-3">
                                    <input type="number" min="1" max="100" step="1" wire:model="budgetSiteRows.{{ $index }}.cpu_share_pct" class="w-24 rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage" />
                                </td>
                                <td class="px-4 py-3">
                                    <input type="number" min="1" max="100" step="1" wire:model="budgetSiteRows.{{ $index }}.mem_share_pct" class="w-24 rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-forest/90 disabled:opacity-50">
                    <span wire:loading.remove wire:target="saveSharedHostBudgets">{{ __('Save budgets') }}</span>
                    <span wire:loading wire:target="saveSharedHostBudgets">{{ __('Saving…') }}</span>
                </button>
                <a href="{{ route('profile.notification-channels.bulk-assign', ['server' => $server->id]) }}" wire:navigate class="text-sm font-semibold text-brand-forest hover:underline">
                    {{ __('Manage alert subscriptions') }}
                </a>
            </div>
        </form>
    @endif
</section>
