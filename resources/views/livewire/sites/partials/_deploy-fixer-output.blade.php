{{-- Live output of a SiteFixers run. Used inside a deploy-hub fix card and
     as the standalone panel on the Deploys sidebar. --}}
@php
    $fixerRun = $fixerRun ?? null;
    $fixerInFlight = $fixerRun && $fixerRun->isInFlight();
    $deployAction = $deployAction ?? 'deployNow';
    $embedded = ! empty($embedded);
@endphp

@if ($fixerRun)
    <div @class([
        'overflow-hidden rounded-xl border border-brand-ink/10',
        'mt-3' => $embedded,
    ])>
        <div class="flex items-center justify-between gap-2 bg-brand-sand/20 px-3 py-2">
            <span class="flex items-center gap-1.5 text-xs font-semibold text-brand-ink">
                @if ($fixerInFlight)
                    <x-spinner size="sm" /> {{ $fixerRun->label ?? __('Fix') }} · {{ __('processing…') }}
                @elseif ($fixerRun->status === 'completed')
                    <x-heroicon-m-check-circle class="h-4 w-4 text-emerald-600" /> {{ $fixerRun->label ?? __('Fix') }} · {{ __('done') }}
                @else
                    <x-heroicon-m-x-circle class="h-4 w-4 text-rose-600" /> {{ $fixerRun->label ?? __('Fix') }} · {{ __('failed') }}
                @endif
            </span>
            @if (! $fixerInFlight && $fixerRun->status === 'completed')
                <button type="button" wire:click="{{ $deployAction }}" class="inline-flex items-center gap-1 rounded-lg bg-brand-ink px-2 py-1 text-xs font-semibold text-brand-cream hover:bg-brand-forest">
                    <x-heroicon-o-rocket-launch class="h-3 w-3" /> {{ __('Deploy now') }}
                </button>
            @endif
        </div>
        @php $fixLines = $fixerRun->lines(); @endphp
        <pre class="max-h-56 overflow-auto bg-brand-ink p-3 font-mono text-xs leading-relaxed text-brand-cream/95" x-init="$el.scrollTop = $el.scrollHeight">@forelse ($fixLines as $ln)@if (! empty($ln['source']))<span class="text-brand-sage">[{{ $ln['source'] }}]</span> @endif{{ $ln['line'] ?? '' }}
@empty{{ __('Queued — starting…') }}@endforelse</pre>
    </div>
@endif
