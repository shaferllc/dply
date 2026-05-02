{{--
  Full preflight + cost preview panel, lifted from the original Servers/Create page.
  Required: $preflight (array)
--}}
@php
    $preflightBadgeClasses = match ($preflight['status'] ?? 'blocked') {
        'ready' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-200',
        default => 'bg-rose-50 text-rose-700 ring-rose-200',
    };
    // Closure used by livewire.servers.partials.preflight-check-row — was defined at the
    // top of the original create.blade.php; the row partial relies on it being in scope.
    $preflightItemClasses = static function (string $severity): string {
        return match ($severity) {
            'info' => 'border-emerald-200 bg-emerald-50/70 text-emerald-900',
            'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
            default => 'border-rose-200 bg-rose-50 text-rose-900',
        };
    };
@endphp

<div class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-base font-semibold text-slate-900">{{ __('Preflight and cost preview') }}</h3>
            <p class="mt-1 text-sm text-slate-600">{{ $preflight['summary'] ?? '' }}</p>
        </div>
        <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] ring-1 {{ $preflightBadgeClasses }}">
            {{ match ($preflight['status'] ?? 'blocked') {
                'ready' => __('Ready'),
                'warning' => __('Needs review'),
                default => __('Blocked'),
            } }}
        </span>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.85fr)]">
        <div class="space-y-4">
            @foreach (($preflight['groups'] ?? []) as $groupKey => $groupChecks)
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                        {{ match ($groupKey) {
                            'account_readiness' => __('Account readiness'),
                            'infrastructure_selection' => __('Infrastructure selection'),
                            'stack_readiness' => __('Stack readiness'),
                            'verification' => __('Verification'),
                            default => __('Cost clarity'),
                        } }}
                    </p>
                    <div class="mt-3 space-y-3">
                        @foreach ($groupChecks as $check)
                            @include('livewire.servers.partials.preflight-check-row', ['check' => $check])
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        @if (! empty($preflight['cost_preview']))
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Estimated provider cost') }}</p>
                <div class="mt-3 space-y-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ __('Provider') }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ str((string) ($preflight['cost_preview']['provider'] ?? ''))->replace('_', ' ')->title() }}</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                        <div>
                            <p class="text-sm font-medium text-slate-900">{{ __('Region') }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ $preflight['cost_preview']['region'] ?? __('Not selected') }}</p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-slate-900">{{ __('Size') }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ $preflight['cost_preview']['size'] ?? __('Not selected') }}</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Estimate') }}</p>
                        <p class="mt-1 text-lg font-semibold text-slate-900">{{ $preflight['cost_preview']['formatted_price'] ?? __('Unavailable') }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $preflight['cost_preview']['detail'] ?? '' }}</p>
                    </div>
                    @if (($preflight['cost_preview']['extras'] ?? []) !== [])
                        <div class="space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Known extras') }}</p>
                            @foreach ($preflight['cost_preview']['extras'] as $extra)
                                <div class="rounded-xl border border-slate-200 px-3 py-2">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-sm font-medium text-slate-900">{{ $extra['label'] ?? '' }}</p>
                                        <span class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ str((string) ($extra['state'] ?? ''))->replace('_', ' ')->title() }}</span>
                                    </div>
                                    <p class="mt-1 text-sm text-slate-600">{{ $extra['detail'] ?? '' }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 font-semibold uppercase tracking-[0.16em] text-slate-600">
                            {{ ($preflight['cost_preview']['source'] ?? null) ? str((string) $preflight['cost_preview']['source'])->replace('_', ' ')->title() : __('No price source') }}
                        </span>
                        @if (($preflight['cost_preview']['price_hourly'] ?? null) !== null)
                            <span>{{ __('Hourly: $:amount/hr', ['amount' => number_format((float) $preflight['cost_preview']['price_hourly'], 4)]) }}</span>
                        @endif
                    </div>
                    @if (($preflight['cost_preview']['notes'] ?? []) !== [])
                        <div class="space-y-1 text-xs text-slate-500">
                            @foreach ($preflight['cost_preview']['notes'] as $note)
                                <p>{{ $note }}</p>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
