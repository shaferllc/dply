@include('livewire.partials.console-action-banner-static', [
    'run' => $webserverBannerRun,
    'kindLabels' => (array) config('console_actions.kinds', []),
])

{{-- Operator escape hatch when the switch banner is stuck. Visible whenever
     a webserver_switch row is still in-flight (queued/running, including
     past the staleness threshold) — clicking "Stop & revert" opens the
     confirm-action modal which then calls stopAndRevertWebserverSwitch().
     Mirrors {@see WorkspaceManage::stopAndRevertWebserverSwitch()}. --}}
@if ($webserverSwitchRun !== null && ! $isDeployer && $opsReady)
    @if ($webserverSwitchRun->isInFlight())
        @php
            $revertConfirmTitle = __('Stop and revert webserver switch?');
            $revertConfirmBody = __('This marks the in-flight switch as failed and runs a best-effort cleanup on the server: stop the partial daemon, apt-get remove the new package, drop its repo file, and restart the original webserver. Use this when the install has stalled.');
            $revertConfirmCta = __('Stop & revert');
        @endphp
        <div class="mb-4 -mt-1 flex justify-end">
            <button
                type="button"
                wire:click="openConfirmActionModal('stopAndRevertWebserverSwitch', ['{{ $webserverSwitchRun->id }}'], @js($revertConfirmTitle), @js($revertConfirmBody), @js($revertConfirmCta), true)"
                class="inline-flex items-center gap-1.5 rounded-md border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm hover:bg-rose-50"
            >
                <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                {{ __('Stop & revert') }}
            </button>
        </div>
    @elseif ($webserverSwitchRun->status === 'failed')
        @php
            $cleanupConfirmTitle = __('Clean up failed webserver switch?');
            $cleanupConfirmBody = __('Stops and removes the partial new webserver, deletes dply-written site configs for it, and restarts the original webserver on port 80. Use this when the switch failed during validate or cutover and sites are unreachable.');
            $cleanupConfirmCta = __('Clean up & restore');
        @endphp
        <div class="mb-4 -mt-1 flex justify-end">
            <button
                type="button"
                wire:click="openConfirmActionModal('cleanupFailedWebserverSwitch', ['{{ $webserverSwitchRun->id }}'], @js($cleanupConfirmTitle), @js($cleanupConfirmBody), @js($cleanupConfirmCta), true)"
                class="inline-flex items-center gap-1.5 rounded-md border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm hover:bg-rose-50"
            >
                <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                {{ __('Clean up & restore') }}
            </button>
        </div>
    @endif
@endif
