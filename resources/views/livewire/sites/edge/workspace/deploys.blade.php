<div @if ($isInProgress ?? false) wire:poll.2s @endif>
    @if (($deploymentJourney ?? null) !== null && ($inProgressDeployment ?? null) !== null)
        <div class="border-b border-brand-ink/10">
            @include('livewire.sites.partials.edge.deployment-journey-card', [
                'journey' => $deploymentJourney,
                'deployment' => $inProgressDeployment,
            ])
        </div>
    @endif

    @include('livewire.sites.partials.edge.deploys-table', ['compact' => false])
    @include('livewire.partials.confirm-action-modal')
</div>
