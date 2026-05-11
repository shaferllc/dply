<livewire:sites.site-log-viewer :server="$server" :site="$site" wire:key="site-log-settings-{{ $site->id }}" />

@if ($site->usesDockerRuntime())
    @if ($runtimeErrorConsole)
        <div class="mt-6 space-y-3">
            <div>
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Runtime errors') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('The latest failure or error-focused diagnostics captured for this runtime.') }}</p>
            </div>

            @include('livewire.partials.deployment-activity-console', [
                'title' => __('Runtime errors'),
                'meta' => $runtimeErrorConsole['meta'],
                'transcript' => $runtimeErrorConsole['transcript'],
                'maxHeight' => '20rem',
            ])
        </div>
    @endif

    @if ($runtimeOperationConsoles->isNotEmpty())
        <div class="mt-6 space-y-3">
            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Recent runtime operations') }}</p>
            @foreach ($runtimeOperationConsoles as $runtimeConsole)
                @include('livewire.partials.deployment-activity-console', [
                    'title' => $runtimeConsole['title'],
                    'meta' => $runtimeConsole['meta'],
                    'transcript' => $runtimeConsole['transcript'],
                    'maxHeight' => '18rem',
                ])
            @endforeach
        </div>
    @endif
@endif

<x-cli-snippet tone="stub" />
