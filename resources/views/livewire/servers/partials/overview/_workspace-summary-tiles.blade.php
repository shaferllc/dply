{{-- Workspace summary section: compact head + the shared tile grid.

     This file used to re-implement all five tiles verbatim from
     _summary-tiles-grid; it now includes it, so the two surfaces can't drift. --}}
@if (! $isDedicatedServiceRoleHost)
<section class="dply-card overflow-hidden p-0">
    <x-workspace-panel-head
        icon="heroicon-o-squares-2x2"
        :title="__('Workspace summary')"
        :note="__('Each tile drops you onto its full workspace page.')"
        class="border-b border-brand-ink/10"
    />
    <div class="px-5 py-3 sm:px-6">
        @include('livewire.servers.partials.overview._summary-tiles-grid')
    </div>
</section>
@endif
