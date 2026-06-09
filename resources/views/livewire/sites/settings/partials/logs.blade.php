{{-- Logging configuration — full config/logging.php editor (managed logging).
     Shown on hosts where dply overlays the generated file (VM sites). --}}
@if (method_exists($this, 'hydrateLoggingSpec') && $server->hostCapabilities()->supportsEnvPushToHost())
    @include('livewire.sites.settings.partials.logging-editor')

    {{-- App logs surface — only when a dply Realtime channel is configured. --}}
    @php
        $loggingBinding = $site->bindings->firstWhere('type', 'logging');
        $hasDplyRealtime = collect(is_array($loggingBinding?->config) ? ($loggingBinding->config['channels'] ?? []) : [])
            ->contains(fn ($c) => is_array($c) && ($c['type'] ?? null) === 'dply_realtime');
    @endphp
    @if ($hasDplyRealtime)
        <livewire:sites.site-app-logs :site="$site" wire:key="site-app-logs-{{ $site->id }}" />
    @endif
@endif

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

<x-cli-snippet :commands="[
    ['label' => __('Tail logs'), 'command' => 'dply sites:logs '.$site->slug.' --tail'],
    ['label' => __('Show log files'), 'command' => 'dply sites:logs:list '.$site->slug],
]" />

{{-- The shared binding modal lives in the environment/resources partial. Include
     it here so the drain config card above can open it when the user is on the
     Logs tab. The resources card itself won't render (logging is excluded from
     the runtime-bindings filter), but the modal element needs to be in the DOM. --}}
@include('livewire.sites.settings.partials.environment.resources')
