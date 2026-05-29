<div>
    <x-fleet-shell
        :title="__('Deploy intelligence')"
        :description="__('Proactive findings across every site in the org — slow builds, TLS expirations, env drift between preview and production. The scanner runs hourly; dismiss an alert to silence the condition until it materially changes.')"
        :section="__('Intelligence')"
    >
        <x-slot:actions>
            <button type="button" wire:click="rescan" wire:loading.attr="disabled" wire:target="rescan"
                class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 text-brand-sage" wire:loading.class="animate-spin" wire:target="rescan" aria-hidden="true" />
                <span wire:loading.remove wire:target="rescan">{{ __('Scan now') }}</span>
                <span wire:loading wire:target="rescan">{{ __('Scanning…') }}</span>
            </button>
        </x-slot:actions>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-fleet-stat :label="__('Open')">
            <p class="mt-2 text-3xl font-semibold tabular-nums {{ $totals['open'] > 0 ? 'text-amber-600' : 'text-brand-ink' }}">{{ $totals['open'] }}</p>
        </x-fleet-stat>
        <x-fleet-stat :label="__('Auto-resolved')">
            <p class="mt-2 text-3xl font-semibold tabular-nums text-emerald-600">{{ $totals['resolved'] }}</p>
        </x-fleet-stat>
        <x-fleet-stat :label="__('Dismissed')">
            <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $totals['dismissed'] }}</p>
        </x-fleet-stat>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <span class="me-1 text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Show') }}</span>
        @foreach (['open' => __('Open'), 'resolved' => __('Resolved'), 'dismissed' => __('Dismissed'), 'all' => __('All')] as $value => $label)
            <x-fleet-pill :active="$showFilter === $value" wire:click="$set('showFilter', '{{ $value }}')">{{ $label }}</x-fleet-pill>
        @endforeach
        <span class="ms-3 me-1 text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Rule') }}</span>
        @foreach (['' => __('All'), 'slow_build' => __('Slow build'), 'tls_expiring' => __('TLS expiring'), 'env_drift' => __('Env drift')] as $value => $label)
            <x-fleet-pill :active="$ruleFilter === $value" wire:click="$set('ruleFilter', '{{ $value }}')">{{ $label }}</x-fleet-pill>
        @endforeach
    </div>

    @if ($alerts->isEmpty())
        @if ($showFilter === 'open' && $ruleFilter === '')
            <x-fleet-empty :title="__('Nothing to act on.')">
                <p class="mt-1">{{ __('The scanner has nothing open across this org. The Scan now button forces an immediate re-check.') }}</p>
            </x-fleet-empty>
        @else
            <x-fleet-empty>{{ __('No alerts match the current filters.') }}</x-fleet-empty>
        @endif
    @else
        <ul class="space-y-3">
            @foreach ($alerts as $alert)
                @php
                    $tone = match ($alert->severity) {
                        'danger' => ['border' => 'border-rose-200', 'bg' => 'bg-rose-50/40', 'dot' => 'bg-rose-500', 'pill' => 'bg-rose-100 text-rose-900 ring-rose-200'],
                        'warning' => ['border' => 'border-amber-200', 'bg' => 'bg-amber-50/40', 'dot' => 'bg-amber-500', 'pill' => 'bg-amber-100 text-amber-900 ring-amber-200'],
                        default => ['border' => 'border-brand-ink/10', 'bg' => 'bg-white', 'dot' => 'bg-brand-sage', 'pill' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25'],
                    };
                    $ruleLabel = match ($alert->rule_key) {
                        'slow_build' => __('Slow build'),
                        'tls_expiring' => __('TLS expiring'),
                        'env_drift' => __('Env drift'),
                        default => $alert->rule_key,
                    };
                @endphp
                <li class="rounded-2xl border {{ $tone['border'] }} {{ $tone['bg'] }} p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="h-2 w-2 rounded-full {{ $tone['dot'] }}" aria-hidden="true"></span>
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 {{ $tone['pill'] }}">
                                    {{ $ruleLabel }}
                                </span>
                                <span class="text-[11px] font-semibold uppercase tracking-wider text-brand-moss">{{ $alert->severity }}</span>
                                @if ($alert->resolved_at)
                                    <span class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">{{ __('resolved') }}</span>
                                @elseif ($alert->dismissed_at)
                                    <span class="text-[11px] font-semibold uppercase tracking-wider text-brand-moss">{{ __('dismissed') }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-sm font-semibold text-brand-ink">{{ $alert->title }}</p>
                            @if ($alert->summary)
                                <p class="mt-1 text-sm text-brand-moss">{{ $alert->summary }}</p>
                            @endif
                            <p class="mt-2 text-xs text-brand-mist">
                                @if ($alert->first_observed_at)
                                    {{ __('First seen :ago', ['ago' => $alert->first_observed_at->diffForHumans()]) }}
                                @endif
                                @if ($alert->last_observed_at)
                                    · {{ __('last :ago', ['ago' => $alert->last_observed_at->diffForHumans()]) }}
                                @endif
                            </p>
                        </div>
                        @if ($alert->isOpen())
                            <button type="button" wire:click="dismiss('{{ $alert->id }}')"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Dismiss') }}
                            </button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
    </x-fleet-shell>
</div>
