@php
    $isLocked = ($lockedTab ?? '') !== '';
@endphp

@unless ($isLocked)
{{-- Page identity lives on the host shell (standalone panel-head / Deployments
     card). Keep only the flush tab strip here. --}}
<div class="border-b border-brand-ink/10 px-3 py-1.5 sm:px-4">
    <x-server-workspace-tablist
        :aria-label="__('Pipeline sections')"
        scroll
        bare
        class="!mb-0 w-full"
    >
        @foreach ($pipelineTabs as $tabId => $tabLabel)
            <x-server-workspace-tab
                id="pipeline-tab-{{ $tabId }}"
                :active="$pipelineTab === $tabId"
                :icon="$pipelineTabIcons[$tabId] ?? 'heroicon-o-adjustments-horizontal'"
                wire:click="setPipelineTab('{{ $tabId }}')"
            >{{ __($tabLabel) }}</x-server-workspace-tab>
        @endforeach
    </x-server-workspace-tablist>
</div>
@endunless

<div class="min-w-0" wire:key="pipeline-panel-{{ $pipelineTab }}">
    {{-- Sub-tab switch shows the skeleton placeholder instantly (client-side via
         wire:loading, no spinner) and swaps the real panel in when setPipelineTab's
         single round-trip lands. --}}
    <div class="hidden" wire:loading.class.remove="hidden" wire:target="setPipelineTab">
        @include('livewire.sites.partials._panel-skeleton')
    </div>
    <div wire:loading.class="hidden" wire:target="setPipelineTab">
    @if ($pipelineTab === 'overview')
        @include('livewire.sites.partials.pipeline._tab-overview')
    @elseif ($pipelineTab === 'steps')
        @include('livewire.sites.partials.pipeline._tab-steps')
    @elseif ($pipelineTab === 'rollout')
        @include('livewire.sites.partials.pipeline._tab-rollout')
    @elseif ($pipelineTab === 'reference')
        @include('livewire.sites.partials.pipeline._tab-reference')
    @endif
    </div>
</div>
