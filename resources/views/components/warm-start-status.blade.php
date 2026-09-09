@props(['status' => null])

{{-- Warm start's only evidence line. `null` means no ping has ever landed,
     which is a stopped control-plane scheduler far more often than it is a
     new function — say so rather than showing an empty, healthy-looking row.
     Rendered on both warm-start surfaces off one WarmStartStatus payload. --}}
@if ($status === null)
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900']) }}>
        <p class="font-semibold">{{ __('No ping recorded yet — nothing is holding this function warm.') }}</p>
        <p class="mt-0.5">{{ __('Warm start rides dply\'s one-minute cron. If this does not fill in within a couple of minutes, the control plane\'s scheduler is not running (cron in production, `php artisan schedule:work` locally).') }}</p>
    </div>
@elseif ($status['stale'])
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900']) }}>
        <p class="font-semibold">{{ __('Last ping :when — dply expects one every minute.', ['when' => $status['human']]) }}</p>
        <p class="mt-0.5">{{ __('The control plane\'s scheduler looks stopped, so this function is going cold between visits.') }}</p>
    </div>
@elseif (! $status['ok'])
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900']) }}>
        <p class="font-semibold">{{ __('Pinged :when, but the function did not answer.', ['when' => $status['human']]) }}</p>
        <p class="mt-0.5">{{ __('dply is warming on schedule — the failure is in the function itself. Check the Logs tab.') }}</p>
    </div>
@else
    {{-- Healthy state carries the same box as the three problem states above,
         for the same reason <x-fact-row> exists: a bare line of 2xs grey read
         as leftover debug text floating under the toggle, not as the panel's
         answer. Labelled values, because "265 ms" alone says nothing about
         whether that is the ping or the page. --}}
    <div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-2']) }}>
        <span class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-brand-ink">
            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-brand-forest" aria-hidden="true"></span>
            {{ __('Warming') }}
        </span>

        <span class="h-3.5 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>

        <span class="inline-flex items-baseline gap-1.5">
            <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Last ping') }}</span>
            <span class="text-xs text-brand-ink" title="{{ $status['iso'] }}">{{ $status['human'] }}</span>
        </span>

        <span class="inline-flex items-baseline gap-1.5">
            <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Took') }}</span>
            <span class="text-xs tabular-nums text-brand-ink">{{ __(':ms ms', ['ms' => number_format($status['durationMs'])]) }}</span>
        </span>

        {{-- The one value that can be bad while everything else is fine: a
             ping that itself cold-started means the gap between pings is
             losing the race with the platform's idle eviction. --}}
        <span class="inline-flex items-baseline gap-1.5">
            <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Container') }}</span>
            <span @class([
                'text-xs font-semibold',
                'text-amber-700' => $status['cold'],
                'text-brand-forest' => ! $status['cold'],
            ])>{{ $status['cold'] ? __('cold start') : __('warm') }}</span>
        </span>
    </div>
@endif
