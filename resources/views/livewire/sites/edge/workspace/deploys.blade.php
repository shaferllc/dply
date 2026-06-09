<div class="space-y-6" @if ($isInProgress ?? false) wire:poll.2s @endif>
    @include('livewire.sites.partials.edge.hero')

    @if (($deploymentJourney ?? null) !== null && ($inProgressDeployment ?? null) !== null)
        @include('livewire.sites.partials.edge.deployment-journey-card', [
            'journey' => $deploymentJourney,
            'deployment' => $inProgressDeployment,
        ])
    @endif

    @include('livewire.sites.partials.edge.deploys-table', ['compact' => false])
    @include('livewire.partials.confirm-action-modal')
</div>
