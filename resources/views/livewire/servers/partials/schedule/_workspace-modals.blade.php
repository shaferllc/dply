@php
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
@endphp

<x-modal name="schedule-disable-monitoring" :show="$showDisableMonitoringModal" wire:model="showDisableMonitoringModal">
    <div class="p-6">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Stop monitoring this scheduler?') }}</h3>
        <p class="mt-2 text-sm text-brand-moss">
            {{ __('The scheduler keeps running on the server. Dply will stop tracking tick health and close any related Insights findings.') }}
        </p>
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <button type="button" wire:click="closeDisableMonitoringModal" class="{{ $btnSecondary }}">
                {{ __('Cancel') }}
            </button>
            <button type="button" wire:click="confirmDisableMonitoring" class="inline-flex items-center justify-center gap-2 rounded-lg bg-red-700 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-white shadow-sm hover:bg-red-800 transition-colors">
                {{ __('Stop monitoring') }}
            </button>
        </div>
    </div>
</x-modal>
