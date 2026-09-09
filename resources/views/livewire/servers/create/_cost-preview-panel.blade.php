{{--
  Cost-preview panel — extracted from the preflight container so it can live in
  the review-page sidebar instead of squeezing in next to the preflight checks.
  Renders nothing when no cost preview is available.

  Required: $preflight (array) with optional cost_preview key.

  Dense by design, and shaped for the narrow sidebar it lives in: the headline
  price rides in the panel head (it was a boxed callout below three stacked
  label-over-value pairs), the three facts are hairline rows with the value
  right-aligned rather than six stacked lines, and each "known extra" is one
  truncated line instead of a bordered card. All of it is reference material you
  glance at — the number is the only thing anyone reads twice.
--}}
@if (! empty($preflight['cost_preview']))
    @php
        $cost = $preflight['cost_preview'];
        $costFacts = [
            __('Provider') => str((string) ($cost['provider'] ?? ''))->replace('_', ' ')->title(),
            __('Region') => $cost['region'] ?? __('Not selected'),
            __('Size') => $cost['size'] ?? __('Not selected'),
        ];
        $costSource = ($cost['source'] ?? null)
            ? str((string) $cost['source'])->replace('_', ' ')->title()
            : __('No price source');
    @endphp

    <section class="dply-card overflow-hidden">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-banknotes"
            :title="__('Estimated provider cost')"
            :note="$cost['detail'] ?? ''"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <span class="inline-flex h-6 shrink-0 items-center rounded-full bg-brand-ink px-2 text-xs font-semibold tabular-nums text-brand-cream">
                    {{ $cost['formatted_price'] ?? __('Unavailable') }}
                </span>
            </x-slot:actions>
        </x-workspace-panel-head>

        <dl class="divide-y divide-brand-ink/8">
            @foreach ($costFacts as $label => $value)
                <div class="flex items-baseline justify-between gap-3 px-3 py-1.5 sm:px-4">
                    <dt class="shrink-0 text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $label }}</dt>
                    <dd class="min-w-0 truncate text-xs font-medium text-brand-ink">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if (($cost['extras'] ?? []) !== [])
            <div class="flex items-center gap-2 border-y border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5 sm:px-4">
                <p class="shrink-0 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Known extras') }}</p>
                <span class="h-px min-w-0 flex-1 bg-brand-ink/8" aria-hidden="true"></span>
                <span class="shrink-0 text-2xs tabular-nums text-brand-mist">{{ count($cost['extras']) }}</span>
            </div>
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($cost['extras'] as $extra)
                    <li class="flex items-baseline gap-2 px-3 py-1.5 sm:px-4">
                        <span class="shrink-0 text-xs font-semibold text-brand-ink">{{ $extra['label'] ?? '' }}</span>
                        {{-- Detail is reference, not instruction: truncated here,
                             full text on hover via tooltip.js. --}}
                        <span class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ $extra['detail'] ?? '' }}">{{ $extra['detail'] ?? '' }}</span>
                        <span class="shrink-0 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ str((string) ($extra['state'] ?? ''))->replace('_', ' ')->title() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-brand-ink/10 px-3 py-1.5 sm:px-4">
            <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/50 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss ring-1 ring-brand-ink/10">
                {{ $costSource }}
            </span>
            @if (($cost['price_hourly'] ?? null) !== null)
                <span class="text-2xs tabular-nums text-brand-mist">{{ __('Hourly: $:amount/hr', ['amount' => number_format((float) $cost['price_hourly'], 4)]) }}</span>
            @endif
            @foreach (($cost['notes'] ?? []) as $note)
                <span class="h-3 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                <span class="min-w-0 text-2xs text-brand-mist">{{ $note }}</span>
            @endforeach
        </div>
    </section>
@endif
