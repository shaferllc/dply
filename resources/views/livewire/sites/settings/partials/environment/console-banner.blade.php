    {{-- wire:init above lazy-fires the first-visit sync once after the page
         renders. The conditions ensure it only runs when truly necessary —
         empty cache, no recorded origin, no in-flight job. The sync banner
         shows progress at the top of the page; the keys list re-renders
         when the job completes (see wire:poll below). --}}
    {{-- On the Settings page (section === 'environment') the console-action
         banner is mounted at the top level. In the deploy hub the view runs
         under section 'deploy' and renders no banner, so mount one here for the
         env run. Guarding on $section avoids a double banner on Settings. --}}
    @if (($section ?? '') !== 'environment' && $envConsoleRun?->id !== ($this->watchedConsoleRunId ?? null))
        @if ($envConsoleRun)
            <div
                id="site-console-action-banner"
                x-data="{}"
                x-on:dply-console-action-focus.window="$nextTick(() => { const el = document.getElementById('site-console-action-banner'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); })"
            >
                @include('livewire.partials.console-action-banner-static', [
                    'run' => $envConsoleRun,
                    'kindLabels' => (array) config('console_actions.kinds', []),
                ])
            </div>
        @endif
    @endif
