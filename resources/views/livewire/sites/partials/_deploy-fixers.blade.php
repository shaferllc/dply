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
        // Pre-phase failures (clone/auth/connection) record nothing in any
        // step — their cause lives only in log_output. Include it so fixers
        // can still be detected for deploys that died before the timeline.
        $failOutput .= ' '.(string) $latest->log_output;
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

{{-- Inline smart fixes for a failed deploy. The deploy hub lists each unique
     fix as its own card ({@see _deploy-fix-cards}) — skip this list there. --}}
@if ($deployFixers !== [] && empty($hideFixerList))
    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3">
        <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-amber-700">
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
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-amber-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-amber-700 disabled:opacity-60"
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

{{-- Live output of the fix currently (or last) run. The deploy hub embeds
     this inside the matching fix card — skip the duplicate below the timeline. --}}
@if ($fixerRun && empty($hideFixerOutput))
    @include('livewire.sites.partials._deploy-fixer-output', [
        'fixerRun' => $fixerRun,
        'deployAction' => $deployAction,
        'embedded' => false,
    ])
@endif
