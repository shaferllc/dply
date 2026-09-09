<x-modal name="schedule-disable-monitoring" :show="$showDisableMonitoringModal" wire:model="showDisableMonitoringModal">
    <div class="px-4 py-4 sm:px-5">
        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Stop monitoring this scheduler?') }}</h3>
        <p class="mt-1.5 text-xs leading-relaxed text-brand-moss">
            {{ __('The scheduler keeps running on the server. Dply will stop tracking tick health and close any related Insights findings.') }}
        </p>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <x-secondary-button size="sm" type="button" wire:click="closeDisableMonitoringModal">
                {{ __('Cancel') }}
            </x-secondary-button>
            <button type="button" wire:click="confirmDisableMonitoring" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-red-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-red-800">
                {{ __('Stop monitoring') }}
            </button>
        </div>
    </div>
</x-modal>
