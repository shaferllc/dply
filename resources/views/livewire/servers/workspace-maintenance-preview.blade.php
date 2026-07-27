@php
    $maintenancePreviewDescription = __('Visitor maintenance window, site impact, and downtime controls — preview what is shipping next.');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="maintenance"
    :title="__('Maintenance')"
    :description="$maintenancePreviewDescription"
    hide-hero
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-wrench class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Maintenance') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ $maintenancePreviewDescription }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="px-5 py-5 sm:px-6">
            <x-maintenance-preview-panel :server="$server" compact />
        </div>
    </section>
</x-server-workspace-layout>
