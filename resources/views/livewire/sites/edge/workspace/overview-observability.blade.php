<div wire:init="loadObservabilityCards" class="space-y-6">
    @if ($observabilityLoaded)
        @include('livewire.sites.partials.edge.traffic-card')
        @include('livewire.sites.partials.edge.billing-card')
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="dply-card animate-pulse px-6 py-10 sm:px-8" aria-hidden="true">
                <div class="h-4 w-40 rounded bg-brand-ink/10"></div>
                <div class="mt-4 h-3 w-full max-w-md rounded bg-brand-ink/8"></div>
            </div>
            <div class="dply-card animate-pulse px-6 py-10 sm:px-8" aria-hidden="true">
                <div class="h-4 w-36 rounded bg-brand-ink/10"></div>
                <div class="mt-4 h-3 w-full max-w-md rounded bg-brand-ink/8"></div>
            </div>
        </div>
    @endif
</div>
