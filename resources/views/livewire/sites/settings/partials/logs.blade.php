<livewire:sites.site-log-viewer :server="$server" :site="$site" wire:key="site-log-settings-{{ $site->id }}" />

@if ($site->usesDockerRuntime())
    @if ($runtimeErrorConsole)
        <div class="mt-6 space-y-3">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-rose-50 text-rose-700 ring-rose-200">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Diagnostics') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Runtime errors') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('The latest failure or error-focused diagnostics captured for this runtime.') }}</p>
                </div>
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
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Recent runtime operations') }}</p>
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
