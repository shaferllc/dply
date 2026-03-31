{{-- Requires ConfirmsServerMonitoringInstall on the Livewire component. --}}
@if ($showInstallMonitoringModal ?? false)
    @php
        $kind = $installMonitoringModalKind ?? 'step1';
        $title = match ($kind) {
            'redeploy' => __('Deploy metrics script'),
            default => __('Install Python for monitoring'),
        };
        $body = match ($kind) {
            'redeploy' => __('Install or update Python if needed, and deploy the latest metrics script to ~/.dply/bin/ on this server?'),
            'services' => (string) (config('server_services.install_actions.install_monitoring_prerequisites.confirm') ?? __('Run this install?')),
            default => __('Install Python 3 on this server so Dply can collect metrics?'),
        };
        $confirmLabel = match ($kind) {
            'redeploy' => __('Deploy'),
            default => __('Run install'),
        };
    @endphp
    <div
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="install-monitoring-modal-title"
        x-data
        x-on:keydown.escape.window="$wire.closeInstallMonitoringModal()"
    >
        <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeInstallMonitoringModal"></div>
        <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
            <div
                class="relative w-full max-w-md rounded-2xl border border-brand-ink/10 bg-white shadow-xl"
                wire:click.stop
            >
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-7">
                    <h2 id="install-monitoring-modal-title" class="text-lg font-semibold text-brand-ink">{{ $title }}</h2>
                </div>
                <div class="px-6 py-5 sm:px-7">
                    <p class="text-sm leading-relaxed text-brand-moss">{{ $body }}</p>
                </div>
                <div class="flex flex-col-reverse gap-2 border-t border-brand-ink/10 px-6 py-4 sm:flex-row sm:justify-end sm:gap-3 sm:px-7">
                    <button
                        type="button"
                        wire:click="closeInstallMonitoringModal"
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="confirmInstallMonitoring"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="confirmInstallMonitoring">{{ $confirmLabel }}</span>
                        <span wire:loading wire:target="confirmInstallMonitoring">{{ __('Running…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
