{{--
    Add / preview CTA for a single edge proxy target in the overview picker.
    Expects: $key, $info, $isActiveEdge, $activeEdgeProxy, $edgeProxyCatalog,
    $activeWebserver, $inflightEdge, $inflightSwitch, $isDeployer, $opsReady,
    optional $actionInFlight (defaults false), optional $edgeProxyActionTarget.
--}}
@php
    $actionInFlight = $actionInFlight ?? false;
    $inflightEdge = $inflightEdge ?? $this->hasInflightEdgeProxyAction();
    $inflightSwitch = $inflightSwitch ?? $this->hasInflightWebserverSwitch();
    $edgeProxyActionTarget = $edgeProxyActionTarget ?? null;
    $isInflightTarget = $inflightEdge && $edgeProxyActionTarget === $key;
    $isComingSoon = ! $isActiveEdge && ! empty($info['coming_soon']);
    $edgeProxyLoadingTargets = 'openConfirmActionModal,addEdgeProxy,confirmActionModal';
@endphp

@if ($isInflightTarget)
    <div class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-brand-forest/20 bg-brand-sage/10 px-3 py-2 text-xs font-semibold text-brand-forest">
        <x-spinner variant="forest" size="sm" />
        <span>
            @if ($isActiveEdge)
                {{ __('Removing :name…', ['name' => $info['label']]) }}
            @elseif ($activeEdgeProxy !== null)
                {{ __('Switching to :name…', ['name' => $info['label']]) }}
            @else
                {{ __('Adding :name…', ['name' => $info['label']]) }}
            @endif
        </span>
    </div>
@elseif ($isActiveEdge)
    @php
        $restoreWebserverLabel = $edgeProxyPreviousLabel ?? __('your webserver');
    @endphp
    <button
        type="button"
        wire:click="openConfirmActionModal('removeEdgeProxy', [], @js(__('Remove edge proxy')), @js(__('Remove :name? Port :port will return to :webserver — the engine that was serving sites before the edge proxy was added. Caddy backends on high ports are removed.', ['name' => $info['label'], 'port' => 80, 'webserver' => $restoreWebserverLabel])), @js(__('Remove')), true)"
        wire:loading.attr="disabled"
        wire:target="openConfirmActionModal,removeEdgeProxy,confirmActionModal"
        @disabled($isDeployer || ! $opsReady || $inflightEdge || $actionInFlight)
        class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
    >
        <span wire:loading.remove wire:target="openConfirmActionModal,removeEdgeProxy,confirmActionModal" class="inline-flex items-center gap-1.5">
            <x-heroicon-o-trash class="h-3.5 w-3.5" />
            {{ __('Remove :name', ['name' => $info['label']]) }}
        </span>
        <span wire:loading wire:target="openConfirmActionModal,removeEdgeProxy,confirmActionModal" class="inline-flex items-center gap-1.5">
            <x-spinner variant="forest" size="sm" />
            {{ __('Queueing…') }}
        </span>
    </button>
@elseif ($activeEdgeProxy !== null)
    @php
        $otherLabel = $edgeProxyCatalog[$activeEdgeProxy]['label'] ?? ucfirst($activeEdgeProxy);
        $switchTitle = __('Switch to :name edge proxy', ['name' => $info['label']]);
        $switchBody = __('Replace :current with :name on :port? Caddy site backends stay on high ports — only the edge router changes.', [
            'current' => $otherLabel,
            'name' => $info['label'],
            'port' => 80,
        ]);
        $switchConfirm = __('Switch to :name', ['name' => $info['label']]);
    @endphp
    <button
        type="button"
        wire:click="openConfirmActionModal('addEdgeProxy', ['{{ $key }}'], @js($switchTitle), @js($switchBody), @js($switchConfirm), false)"
        wire:loading.attr="disabled"
        wire:target="{{ $edgeProxyLoadingTargets }}"
        @disabled($isDeployer || ! $opsReady || $inflightEdge || $inflightSwitch || $actionInFlight)
        class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:opacity-60"
    >
        <span wire:loading.remove wire:target="{{ $edgeProxyLoadingTargets }}" class="inline-flex items-center gap-1.5">
            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
            {{ __('Switch to :name', ['name' => $info['label']]) }}
        </span>
        <span wire:loading wire:target="{{ $edgeProxyLoadingTargets }}" class="inline-flex items-center gap-1.5">
            <x-spinner variant="cream" size="sm" />
            {{ __('Queueing…') }}
        </span>
    </button>
@elseif ($inflightEdge)
    <div class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-brand-sand/40 px-3 py-1.5 text-xs font-semibold text-brand-mist">
        <x-spinner variant="forest" size="sm" />
        <span>{{ __('Edge proxy action in progress…') }}</span>
    </div>
@elseif ($isComingSoon)
    <div class="mt-3 flex flex-col gap-2">
        <div class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-brand-ink/10 bg-brand-sand/35 px-3 py-1.5 text-xs font-semibold text-brand-moss">
            <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>{{ __('Coming soon') }}</span>
        </div>
        <button
            type="button"
            wire:click="setWorkspaceTab('{{ $key }}')"
            class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40"
        >
            <x-heroicon-o-eye class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ __('Preview') }}
        </button>
    </div>
@else
    <button
        type="button"
        wire:click="openConfirmActionModal('addEdgeProxy', ['{{ $key }}'], @js(__('Add :name edge proxy', ['name' => $info['label']])), @js(__('Install :name in front of the webserver? Caddy will be installed as the per-site backend; your current webserver (:active) will be stopped.', ['name' => $info['label'], 'active' => $activeWebserver])), @js(__('Add :name', ['name' => $info['label']])), false)"
        wire:loading.attr="disabled"
        wire:target="{{ $edgeProxyLoadingTargets }}"
        @disabled($isDeployer || ! $opsReady || $inflightEdge || $inflightSwitch || $actionInFlight)
        class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:opacity-60"
    >
        <span wire:loading.remove wire:target="{{ $edgeProxyLoadingTargets }}" class="inline-flex items-center gap-1.5">
            <x-heroicon-o-arrow-up-tray class="h-3.5 w-3.5" />
            {{ __('Add :name', ['name' => $info['label']]) }}
        </span>
        <span wire:loading wire:target="{{ $edgeProxyLoadingTargets }}" class="inline-flex items-center gap-1.5">
            <x-spinner variant="cream" size="sm" />
            {{ __('Queueing…') }}
        </span>
    </button>
@endif
