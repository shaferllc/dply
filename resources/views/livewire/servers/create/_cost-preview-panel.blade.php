{{--
  Cost-preview panel — extracted from the preflight container so it can
  live in the review-page sidebar instead of squeezing in next to the
  preflight checks. Renders nothing when no cost preview is available.

  Required: $preflight (array) with optional cost_preview key.
--}}
@if (! empty($preflight['cost_preview']))
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Estimated provider cost') }}</p>
        <div class="mt-3 space-y-3">
            <div>
                <p class="text-sm font-medium text-slate-900">{{ __('Provider') }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ str((string) ($preflight['cost_preview']['provider'] ?? ''))->replace('_', ' ')->title() }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">{{ __('Region') }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ $preflight['cost_preview']['region'] ?? __('Not selected') }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">{{ __('Size') }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ $preflight['cost_preview']['size'] ?? __('Not selected') }}</p>
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
