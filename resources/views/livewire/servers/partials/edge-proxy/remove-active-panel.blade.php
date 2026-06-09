{{--
    Remove the active edge proxy (Traefik / HAProxy). Expects edge-proxy workspace view data:
    $info, $edgeProxyPreviousLabel, $isDeployer, $opsReady, $inflightEdge, $inflightSwitch, $actionInFlight
--}}
@php
    $inflightEdge = $inflightEdge ?? $this->hasInflightEdgeProxyAction();
    $inflightSwitch = $inflightSwitch ?? $this->hasInflightWebserverSwitch();
    $actionInFlight = $actionInFlight ?? false;
@endphp
<div class="mt-6 rounded-xl border border-rose-200/80 bg-rose-50/40 p-4 sm:p-5">
    <h4 class="text-sm font-semibold text-brand-ink">{{ __('Remove :name', ['name' => $info['label']]) }}</h4>
    <p class="mt-1 text-xs leading-relaxed text-brand-moss">
        {{ __('Stop :name on :port and restore :webserver as the webserver serving your sites. This cannot be undone from the UI — you can add an edge proxy again from the Add / remove tab.', [
            'name' => $info['label'],
            'port' => 80,
            'webserver' => $edgeProxyPreviousLabel,
        ]) }}
    </p>
    <button
        type="button"
        wire:click="openConfirmActionModal('removeEdgeProxy', [], @js(__('Remove edge proxy')), @js(__('Remove :name? Port :port will return to :webserver.', ['name' => $info['label'], 'port' => 80, 'webserver' => $edgeProxyPreviousLabel])), @js(__('Remove')), true)"
        @disabled($isDeployer || ! $opsReady || $inflightEdge || $inflightSwitch || $actionInFlight)
        class="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3.5 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
    >
        <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
        {{ __('Remove :name and restore :webserver', ['name' => $info['label'], 'webserver' => $edgeProxyPreviousLabel]) }}
    </button>
</div>
