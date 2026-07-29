<div wire:init="loadObservabilityCards">
    @if ($observabilityLoaded)
        <div class="grid gap-0 border-b border-brand-ink/10 lg:grid-cols-2">
            <div class="border-b border-brand-ink/10 lg:border-b-0 lg:border-e dark:border-brand-mist/15">
                @include('livewire.sites.partials.edge.traffic-card')
            </div>
            <div>
                @include('livewire.sites.partials.edge.billing-card')
            </div>
        </div>
    @else
        <div class="grid gap-0 border-b border-brand-ink/10 lg:grid-cols-2" aria-hidden="true">
            <div class="animate-pulse border-b border-brand-ink/10 px-5 py-8 sm:px-6 lg:border-b-0 lg:border-e dark:border-brand-mist/15">
                <div class="h-4 w-40 rounded bg-brand-ink/10"></div>
                <div class="mt-4 h-3 w-full max-w-md rounded bg-brand-ink/8"></div>
            </div>
            <div class="animate-pulse px-5 py-8 sm:px-6">
                <div class="h-4 w-36 rounded bg-brand-ink/10"></div>
                <div class="mt-4 h-3 w-full max-w-md rounded bg-brand-ink/8"></div>
            </div>
        </div>
    @endif
</div>
