<div class="mx-auto max-w-7xl px-6 py-10">
    @include('livewire.fleet._tabs')
    <header class="mb-6 border-b border-brand-ink/10 pb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Deploy intelligence') }}</h1>
            <p class="mt-1 text-sm text-brand-moss max-w-2xl">
                {{ __('Proactive findings across every site in the org — slow builds, TLS expirations, env drift between preview and production. The scanner runs hourly; dismiss an alert to silence the condition until it materially changes.') }}
            </p>
        </div>
        <button type="button" wire:click="rescan" wire:loading.attr="disabled" wire:target="rescan"
            class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 text-brand-sage" wire:loading.class="animate-spin" wire:target="rescan" aria-hidden="true" />
            <span wire:loading.remove wire:target="rescan">{{ __('Scan now') }}</span>
            <span wire:loading wire:target="rescan">{{ __('Scanning…') }}</span>
        </button>
    </header>

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-amber-200 bg-amber-50/60 px-5 py-4">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-900">{{ __('Open') }}</p>
            <p class="mt-1 text-2xl font-semibold text-amber-900">{{ $totals['open'] }}</p>
        </div>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 px-5 py-4">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800">{{ __('Auto-resolved') }}</p>
            <p class="mt-1 text-2xl font-semibold text-emerald-900">{{ $totals['resolved'] }}</p>
        </div>
        <div class="rounded-2xl border border-brand-ink/10 bg-white px-5 py-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Dismissed') }}</p>
            <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ $totals['dismissed'] }}</p>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <span class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss me-1">{{ __('Show') }}</span>
        @foreach (['open' => __('Open'), 'resolved' => __('Resolved'), 'dismissed' => __('Dismissed'), 'all' => __('All')] as $value => $label)
            <button type="button" wire:click="$set('showFilter', '{{ $value }}')"
                @class([
                    'rounded-full border px-3 py-1 text-xs font-semibold transition',
                    'border-brand-ink bg-brand-ink text-brand-cream' => $showFilter === $value,
                    'border-brand-ink/15 bg-white text-brand-moss hover:text-brand-ink' => $showFilter !== $value,
                ])>
                {{ $label }}
            </button>
        @endforeach
        <span class="ms-3 text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss me-1">{{ __('Rule') }}</span>
        @foreach (['' => __('All'), 'slow_build' => __('Slow build'), 'tls_expiring' => __('TLS expiring'), 'env_drift' => __('Env drift')] as $value => $label)
            <button type="button" wire:click="$set('ruleFilter', '{{ $value }}')"
                @class([
                    'rounded-full border px-3 py-1 text-xs font-semibold transition',
                    'border-brand-ink bg-brand-ink text-brand-cream' => $ruleFilter === $value,
                    'border-brand-ink/15 bg-white text-brand-moss hover:text-brand-ink' => $ruleFilter !== $value,
                ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($alerts->isEmpty())
        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 p-8 text-center text-sm text-brand-moss">
            @if ($showFilter === 'open' && $ruleFilter === '')
                <p class="font-medium text-brand-ink">{{ __('Nothing to act on.') }}</p>
                <p class="mt-1">{{ __('The scanner has nothing open across this org. The Scan now button forces an immediate re-check.') }}</p>
            @else
                <p>{{ __('No alerts match the current filters.') }}</p>
            @endif
        </div>
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
</div>
