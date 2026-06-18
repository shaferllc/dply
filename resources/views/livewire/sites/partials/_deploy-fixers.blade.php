{{-- Smart fixes for a FAILED deploy — detected from the failed step output
     (e.g. "npm not found" → Install Node.js & npm) and offered inline, plus the
     live output of a fix that's running. Shared by the deploy sidebar
     ({@see \App\Livewire\Sites\DeployControl}) and the main Deploy page
     ({@see \App\Livewire\Sites\DeploymentsList}) so the fix actions are identical
     on both surfaces — driven by the same coordinator state.

     Expects: $latest (SiteDeployment|null), $phases (timeline array),
     $server, $site, and $deployAction (the component method to re-deploy with,
     e.g. 'deploy' on the sidebar, 'deployNow' on the page). --}}
@php
    $deployFixers = [];
    if ($latest && $latest->status === 'failed') {
        $failOutput = '';
        foreach ($phases as $ph) {
            foreach ($ph['steps'] as $st) {
                if (! ($st['ok'] ?? true) && ! ($st['skipped'] ?? false)) {
                    $failOutput .= ' '.($st['output'] ?? '');
                }
            }
        }
        $alreadyRun = $this->completedFixerKeys;
        $deployFixers = collect(\App\Support\Sites\SiteFixers::detect($failOutput))
            ->reject(fn ($fx) => in_array($fx['key'], $alreadyRun, true))
            ->values()
            ->all();
    }

    $fixerRun = $this->fixerRun;
    $fixerInFlight = $fixerRun && $fixerRun->isInFlight();
    $activeFixerKey = $this->fixerRunKey;
@endphp

{{-- Inline smart fixes for a failed deploy. --}}
@if ($deployFixers !== [])
    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3">
        <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">
            <x-heroicon-o-wrench-screwdriver class="h-4 w-4" />
            {{ trans_choice('{1} Suggested fix|[2,*] Suggested fixes', count($deployFixers)) }}
        </p>
        <ul class="mt-2 space-y-2">
            @foreach ($deployFixers as $fx)
                @php $thisRunning = $fixerInFlight && $activeFixerKey === $fx['key']; @endphp
                <li class="flex flex-wrap items-center justify-between gap-2">
                    <span class="min-w-0 flex-1 text-xs text-amber-900">{{ $fx['reason'] }}</span>
                    <button
                        type="button"
                        wire:click="runFixer(@js($fx['key']))"
                        wire:loading.attr="disabled"
                        wire:target="runFixer"
                        @disabled($fixerInFlight)
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-amber-600 px-2.5 py-1.5 text-[11px] font-semibold text-white shadow-sm transition-colors hover:bg-amber-700 disabled:opacity-60"
                    >
                        @if ($thisRunning)
                            <x-spinner variant="white" size="sm" /> {{ __('Processing…') }}
                        @else
                            <x-heroicon-o-play class="h-4 w-4" /> {{ $fx['label'] }}
                        @endif
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Live output of the fix currently (or last) run. --}}
@if ($fixerRun)
    <div class="mt-3 overflow-hidden rounded-xl border border-brand-ink/10">
        <div class="flex items-center justify-between gap-2 bg-brand-sand/20 px-3 py-2">
            <span class="flex items-center gap-1.5 text-[11px] font-semibold text-brand-ink">
                @if ($fixerInFlight)
                    <x-spinner size="sm" /> {{ $fixerRun->label ?? __('Fix') }} · {{ __('processing…') }}
                @elseif ($fixerRun->status === 'completed')
                    <x-heroicon-m-check-circle class="h-4 w-4 text-emerald-600" /> {{ $fixerRun->label ?? __('Fix') }} · {{ __('done') }}
                @else
                    <x-heroicon-m-x-circle class="h-4 w-4 text-rose-600" /> {{ $fixerRun->label ?? __('Fix') }} · {{ __('failed') }}
                @endif
            </span>
            @if (! $fixerInFlight && $fixerRun->status === 'completed')
                <button type="button" wire:click="{{ $deployAction }}" class="inline-flex items-center gap-1 rounded-lg bg-brand-ink px-2 py-1 text-[10px] font-semibold text-brand-cream hover:bg-brand-forest">
                    <x-heroicon-o-rocket-launch class="h-3 w-3" /> {{ __('Deploy now') }}
                </button>
            @endif
        </div>
        @php $fixLines = $fixerRun->lines(); @endphp
        <pre class="max-h-56 overflow-auto bg-brand-ink p-3 font-mono text-[11px] leading-relaxed text-brand-cream/95" x-init="$el.scrollTop = $el.scrollHeight">@forelse ($fixLines as $ln)@if (! empty($ln['source']))<span class="text-brand-sage">[{{ $ln['source'] }}]</span> @endif{{ $ln['line'] ?? '' }}
@empty{{ __('Queued — starting…') }}@endforelse</pre>
    </div>
@endif
