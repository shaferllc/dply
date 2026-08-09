{{--
    Remove the active edge proxy (Traefik / HAProxy). Expects edge-proxy workspace view data:
    $info, $edgeProxyPreviousLabel, $isDeployer, $opsReady, $inflightEdge, $inflightSwitch, $actionInFlight
--}}
@php
    $inflightEdge = $inflightEdge ?? $this->hasInflightEdgeProxyAction();
    $inflightSwitch = $inflightSwitch ?? $this->hasInflightWebserverSwitch();
    $actionInFlight = $actionInFlight ?? false;
@endphp
<div class="flex flex-wrap items-center gap-3 border-b border-rose-200/80 bg-rose-50/40 px-4 py-2.5 sm:px-5">
    <div class="min-w-0 flex-1 basis-72">
        <p class="text-xs font-semibold text-brand-ink">{{ __('Remove :name', ['name' => $info['label']]) }}</p>
        <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
            {{ __('Stop :name on :port and restore :webserver as the webserver serving your sites. This cannot be undone from the UI — you can add an edge proxy again from the Add / remove tab.', [
                'name' => $info['label'],
                'port' => 80,
                'webserver' => $edgeProxyPreviousLabel,
            ]) }}
        </p>
    </div>
    <button
        type="button"
        wire:click="openConfirmActionModal('removeEdgeProxy', [], @js(__('Remove edge proxy')), @js(__('Remove :name? Port :port will return to :webserver.', ['name' => $info['label'], 'port' => 80, 'webserver' => $edgeProxyPreviousLabel])), @js(__('Remove')), true)"
        @disabled($isDeployer || ! $opsReady || $inflightEdge || $inflightSwitch || $actionInFlight)
        class="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-2.5 text-xs font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50 disabled:opacity-60"
    >
        <x-heroicon-m-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        {{ __('Remove and restore :webserver', ['webserver' => $edgeProxyPreviousLabel]) }}
    </button>
</div>
