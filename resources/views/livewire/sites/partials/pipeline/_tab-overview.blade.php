@php
    $overviewStepCount = $pipelineOverviewStepCount ?? $editingDeployPipeline?->steps?->count() ?? $site->deploySteps->count();
    $overviewHookCount = $pipelineOverviewHookCount ?? $editingDeployPipeline?->hooks?->count() ?? $site->deployHooks->count();
    $overviewPipelineName = $pipelineOverviewName ?? $editingDeployPipeline?->name ?? __('Default');
    $overviewPipelineIsActive = $pipelineOverviewIsActive ?? $editingDeployPipeline?->isActiveFor($site) ?? true;
    $nested = (bool) ($isEmbedded ?? false);
    $btnOutline = 'dply-btn dply-btn-xs dply-btn-outline';
@endphp

<section @class([
    'overflow-hidden',
    'dply-card' => ! $nested,
])>
    @unless ($nested)
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-queue-list"
            :title="__('Pipeline overview')"
            :note="$overviewPipelineIsActive
                ? __('Summary of how this site deploys. Edit each area from the tabs above.')
                : __('Counts below are for “:name”—not the pipeline marked Deploy.', ['name' => $overviewPipelineName])"
        />
    @else
        @unless ($overviewPipelineIsActive)
            <div class="border-b border-brand-ink/10 bg-amber-50/50 px-5 py-1.5 text-[11px] text-amber-900 sm:px-6">
                {{ __('Counts below are for “:name”—not the pipeline marked Deploy.', ['name' => $overviewPipelineName]) }}
            </div>
        @endunless
    @endunless

    <dl class="grid grid-cols-2 gap-px border-b border-brand-ink/10 bg-brand-ink/10 sm:grid-cols-4">
        <div class="bg-white px-4 py-2 sm:px-5">
            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Strategy') }}</dt>
            <dd class="mt-0.5 text-xs font-semibold text-brand-ink">{{ $site->deploy_strategy === 'atomic' ? __('Zero downtime') : __('Simple') }}</dd>
        </div>
        <div class="bg-white px-4 py-2 sm:px-5">
            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Steps') }}</dt>
            <dd class="mt-0.5 text-xs font-semibold text-brand-ink">{{ $overviewStepCount }}</dd>
        </div>
        <div class="bg-white px-4 py-2 sm:px-5">
            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Hooks') }}</dt>
            <dd class="mt-0.5 text-xs font-semibold text-brand-ink">{{ $overviewHookCount }}</dd>
        </div>
        <div class="bg-white px-4 py-2 sm:px-5">
            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Health check') }}</dt>
            <dd class="mt-0.5 truncate text-xs font-semibold text-brand-ink" title="{{ $deploy_health_enabled && $zero_downtime_enabled ? $deploy_health_scheme.'://'.$deploy_health_host.$deploy_health_path : '' }}">
                @if ($deploy_health_enabled && $zero_downtime_enabled)
                    <span class="font-mono font-normal text-brand-moss">{{ $deploy_health_path }}</span>
                @else
                    {{ __('Off') }}
                @endif
            </dd>
        </div>
    </dl>

    @if (($pipelineActionableChecks ?? collect())->isNotEmpty())
        <x-site-preflight-issues-panel
            :checks="$pipelineActionableChecks"
            :embedded="$nested"
            :compact="true"
            section-id="pipeline-advisor-overview"
            :title="__('Pipeline review')"
            :description="__('Apply the recommended fix for each flagged step or hook below.')"
        />
    @endif

    <div class="flex flex-wrap gap-1.5 border-t border-brand-ink/10 bg-brand-sand/15 px-4 py-2 sm:px-5">
        <button type="button" wire:click="setPipelineTab('steps')" class="{{ $btnOutline }}">{{ __('Edit steps') }}</button>
        <button type="button" wire:click="setPipelineTab('rollout')" class="{{ $btnOutline }}">{{ __('Edit rollout') }}</button>
        <button type="button" wire:click="setPipelineTab('reference')" class="{{ $btnOutline }}">{{ __('Reference') }}</button>
    </div>
</section>

@if ($site->usesDockerRuntime() || $site->usesKubernetesRuntime())
    @include('livewire.sites.partials.pipeline._runtime-artifacts')
@endif
