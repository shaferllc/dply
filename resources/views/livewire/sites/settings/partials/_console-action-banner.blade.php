{{--
    Section console-action banner (queued env scans, fixes, etc. for the current
    settings section). Gated on an actual run: the static banner renders nothing
    when $run is null, and an empty wrapper div as a space-y sibling pushes the
    real content down with a phantom margin.

    Included at the TOP of <main> for most sections, but on General it renders
    AFTER the Overview card so the site identity stays the first thing seen.
--}}
@if ($sectionConsoleActionKinds !== [] && $sectionConsoleActionRun !== null)
    <div
        id="site-console-action-banner"
        x-data="{}"
        x-on:dply-console-action-focus.window="$nextTick(() => {
            const el = document.getElementById('site-console-action-banner');
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        })"
    >
        @include('livewire.partials.console-action-banner-static', [
            'run' => $sectionConsoleActionRun,
            'kindLabels' => (array) config('console_actions.kinds', []),
        ])
    </div>
@endif
