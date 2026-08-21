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
    <p {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-2xs text-brand-mist']) }}>
        <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-brand-forest" aria-hidden="true"></span>
        <span title="{{ $status['iso'] }}">{{ __('Pinged :when', ['when' => $status['human']]) }}</span>
        <span aria-hidden="true">&middot;</span>
        <span>{{ __(':ms ms', ['ms' => number_format($status['durationMs'])]) }}</span>
        <span aria-hidden="true">&middot;</span>
        <span>{{ $status['cold'] ? __('cold start') : __('served warm') }}</span>
    </p>
@endif
