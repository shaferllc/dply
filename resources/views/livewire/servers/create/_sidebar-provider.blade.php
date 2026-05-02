{{--
  Sidebar for Step 2 / provider mode.
  Shows: provider health, region/size summary, cost teaser.
  Required: $preflight, $catalog, $form
--}}
<div class="space-y-4">
    @php
        $costPreview = $preflight['cost_preview'] ?? null;
        $statusBadgeClasses = match ($preflight['status'] ?? 'blocked') {
            'ready' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'warning' => 'bg-amber-50 text-amber-800 ring-amber-200',
            default => 'bg-rose-50 text-rose-700 ring-rose-200',
        };
        $statusLabel = match ($preflight['status'] ?? 'blocked') {
            'ready' => __('Ready'),
            'warning' => __('Needs review'),
            default => __('Blocked'),
        };
    @endphp

    @php
        $blockingChecks = collect($preflight['checks'] ?? [])
            ->filter(fn ($c) => ($c['blocking'] ?? false) === true)
            ->values();
        $warningChecks = collect($preflight['checks'] ?? [])
            ->filter(fn ($c) => ($c['blocking'] ?? false) === false && ($c['severity'] ?? null) === 'warning')
            ->values();
    @endphp

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Status') }}</p>
                <p class="mt-1 text-sm text-slate-700">{{ $preflight['summary'] ?? __('Pick a provider, region, and plan to continue.') }}</p>
            </div>
            <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] ring-1 {{ $statusBadgeClasses }}">{{ $statusLabel }}</span>
        </div>

        @if ($blockingChecks->isNotEmpty())
            <ul class="mt-3 space-y-2 border-t border-slate-100 pt-3 text-xs">
                @foreach ($blockingChecks as $check)
                    <li class="flex items-start gap-2 text-rose-800">
                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        <span>
                            <span class="font-semibold">{{ $check['label'] }}</span>
                            @if (! empty($check['detail']))
                                <span class="ml-1 text-slate-700">{{ $check['detail'] }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @elseif ($warningChecks->isNotEmpty())
            <ul class="mt-3 space-y-2 border-t border-slate-100 pt-3 text-xs">
                @foreach ($warningChecks as $check)
                    <li class="flex items-start gap-2 text-amber-800">
                        <x-heroicon-o-exclamation-circle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        <span>
                            <span class="font-semibold">{{ $check['label'] }}</span>
                            @if (! empty($check['detail']))
                                <span class="ml-1 text-slate-700">{{ $check['detail'] }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($costPreview)
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Estimated cost') }}</p>
            <p class="mt-2 text-lg font-semibold text-slate-900">{{ $costPreview['formatted_price'] ?? __('Unavailable') }}</p>
            @if (! empty($costPreview['detail']))
                <p class="mt-1 text-xs leading-5 text-slate-600">{{ $costPreview['detail'] }}</p>
            @endif
            <dl class="mt-3 grid gap-2 text-xs">
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500">{{ __('Provider') }}</dt>
                    <dd class="text-right text-slate-800">{{ str((string) ($costPreview['provider'] ?? ''))->replace('_', ' ')->title() }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500">{{ __('Region') }}</dt>
                    <dd class="text-right text-slate-800">{{ $costPreview['region'] ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500">{{ __('Plan') }}</dt>
                    <dd class="text-right text-slate-800">{{ $costPreview['size'] ?? '—' }}</dd>
                </div>
            </dl>
        </div>
    @endif

    <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 text-sm text-slate-700">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Tips') }}</p>
        <ul class="mt-2 space-y-1.5">
            <li>• {{ __('Pick a region close to your users.') }}</li>
            <li>• {{ __('Smaller plans deploy faster, but watch RAM headroom for app + database.') }}</li>
            <li>• {{ __('Recommendations are tuned to your selected role.') }}</li>
        </ul>
    </div>
</div>
