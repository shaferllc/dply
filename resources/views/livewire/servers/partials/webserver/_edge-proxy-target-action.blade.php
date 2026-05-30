{{--
    Add / preview CTA for a single edge proxy target in the overview picker.
    Expects: $key, $info, $isActiveEdge, $activeEdgeProxy, $edgeProxyCatalog,
    $activeWebserver, $inflightEdge, $inflightSwitch, $isDeployer, $opsReady,
    and optional $actionInFlight (defaults false).
--}}
@php
    $actionInFlight = $actionInFlight ?? false;
    $isComingSoon = ! $isActiveEdge && ! empty($info['coming_soon']);
@endphp

@if ($isActiveEdge)
    <button
        type="button"
        wire:click="openConfirmActionModal('removeEdgeProxy', [], @js(__('Remove edge proxy')), @js(__('Remove the :name edge proxy? Caddy will resume serving :80 directly.', ['name' => $info['label']])), @js(__('Remove')), true)"
        @disabled($isDeployer || ! $opsReady || $inflightEdge || $actionInFlight)
        class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
    >
        <x-heroicon-o-trash class="h-3.5 w-3.5" />
        {{ __('Remove :name', ['name' => $info['label']]) }}
    </button>
@elseif ($activeEdgeProxy !== null)
    <button
        type="button"
        @disabled(true)
        class="mt-3 inline-flex w-full cursor-not-allowed items-center justify-center gap-1.5 rounded-lg bg-brand-sand/40 px-3 py-1.5 text-xs font-semibold text-brand-mist"
        title="{{ __('Remove the active edge proxy before switching to another.') }}"
    >
        <x-heroicon-o-no-symbol class="h-3.5 w-3.5" />
        {{ __('Unavailable — remove :other first', ['other' => $edgeProxyCatalog[$activeEdgeProxy]['label']]) }}
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
        @disabled($isDeployer || ! $opsReady || $inflightEdge || $inflightSwitch || $actionInFlight)
        class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:opacity-60"
    >
        <x-heroicon-o-arrow-up-tray class="h-3.5 w-3.5" />
        {{ __('Add :name', ['name' => $info['label']]) }}
    </button>
@endif
