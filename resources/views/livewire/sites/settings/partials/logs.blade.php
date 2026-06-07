{{-- Logging configuration card — channel, level, and optional drain provider --}}
@if (method_exists($this, 'openBindingModal'))
@php
    $loggingBinding = $site->bindings->firstWhere('type', 'logging');
    $logProviderLabels = [
        'papertrail' => 'Papertrail',
        'logtail' => 'Logtail',
        'syslog' => 'Syslog',
        'dply_realtime' => 'dply Realtime',
    ];
@endphp
<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-8 sm:py-6">
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-violet-50 text-violet-700 ring-violet-200">
                    <x-heroicon-o-clipboard-document-list class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Logging') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Logging configuration') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Configure LOG_CHANNEL, log level, and drain provider. dply injects the LOG_* variables at deploy.') }}
                    </p>
                </div>
            </div>
            @if ($loggingBinding === null)
                <button type="button" wire:click="openBindingModal('logging', 'attach')" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    {{ __('Configure') }}
                </button>
            @endif
        </div>
    </div>
    @if ($loggingBinding !== null)
        @php
            $loggingConfig = is_array($loggingBinding->config) ? $loggingBinding->config : [];
            $loggingProvider = (string) ($loggingConfig['provider'] ?? '');
            $loggingProviderLabel = $logProviderLabels[$loggingProvider] ?? str($loggingProvider)->replace('_', ' ')->title();
            $loggingLevel = (string) ($loggingConfig['level'] ?? '');
        @endphp
        <div class="flex items-center justify-between gap-4 px-6 py-4 sm:px-8">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-brand-ink">{{ $loggingProviderLabel ?: __('Custom') }}</p>
                <p class="mt-0.5 font-mono text-xs text-brand-moss">
                    {{ $loggingBinding->name }}@if ($loggingLevel) · {{ $loggingLevel }}@endif
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-800">
                    {{ __('configured') }}
                </span>
                <button type="button" wire:click="openBindingModal('logging', 'attach')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                    <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                    {{ __('Edit') }}
                </button>
                <button type="button" wire:click="openConfirmActionModal('detachBinding', @js([(string) $loggingBinding->id]), @js(__('Remove logging config?')), @js(__('Remove this logging configuration? LOG_CHANNEL and related variables will no longer be injected at deploy.')), @js(__('Remove')), true)" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                    <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                    {{ __('Remove') }}
                </button>
            </div>
        </div>
    @else
        <p class="px-6 py-4 text-xs italic text-brand-mist sm:px-8">{{ __('Not configured — LOG_CHANNEL falls back to the app default.') }}</p>
    @endif
</section>
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
