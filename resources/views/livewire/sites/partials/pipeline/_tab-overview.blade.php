@php
    $overviewStepCount = $pipelineOverviewStepCount ?? $editingDeployPipeline?->steps?->count() ?? $site->deploySteps->count();
    $overviewHookCount = $pipelineOverviewHookCount ?? $editingDeployPipeline?->hooks?->count() ?? $site->deployHooks->count();
    $overviewPipelineName = $pipelineOverviewName ?? $editingDeployPipeline?->name ?? __('Default');
    $overviewPipelineIsActive = $pipelineOverviewIsActive ?? $editingDeployPipeline?->isActiveFor($site) ?? true;
    $nested = (bool) ($isEmbedded ?? false);
@endphp

<section @class([
    'overflow-hidden',
    'dply-card' => ! $nested,
])>
    <div @class([
        'border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6',
        'sm:px-7' => ! $nested,
    ])>
        <p class="text-sm leading-relaxed text-brand-moss">{{ __('Summary of how this site deploys. Edit each area from the tabs above.') }}</p>
        @unless ($overviewPipelineIsActive)
            <p class="mt-2 text-sm text-brand-moss">
                {{ __('Counts below are for “:name”—not the pipeline marked Deploy.', ['name' => $overviewPipelineName]) }}
            </p>
        @endunless
    </div>

    <dl class="grid grid-cols-2 gap-px bg-brand-ink/10 sm:grid-cols-4">
        <div class="bg-white px-5 py-5 sm:px-6">
            <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Strategy') }}</dt>
            <dd class="mt-2 text-sm font-semibold text-brand-ink">{{ $site->deploy_strategy === 'atomic' ? __('Zero downtime (atomic)') : __('Simple (in-place)') }}</dd>
        </div>
        <div class="bg-white px-5 py-5 sm:px-6">
            <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Pipeline steps') }}</dt>
            <dd class="mt-2 text-sm font-semibold text-brand-ink">{{ trans_choice('{0} None|{1} :count step|[2,*] :count steps', $overviewStepCount, ['count' => $overviewStepCount]) }}</dd>
        </div>
        <div class="bg-white px-5 py-5 sm:px-6">
            <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Deploy hooks') }}</dt>
            <dd class="mt-2 text-sm font-semibold text-brand-ink">{{ trans_choice('{0} None|{1} :count hook|[2,*] :count hooks', $overviewHookCount, ['count' => $overviewHookCount]) }}</dd>
        </div>
        <div class="bg-white px-5 py-5 sm:px-6">
            <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Post-deploy health check') }}</dt>
            <dd class="mt-2 text-sm font-semibold text-brand-ink">
                @if ($deploy_health_enabled && $zero_downtime_enabled)
                    {{ __('Enabled') }} — {{ $deploy_health_scheme }}://{{ $deploy_health_host }}{{ $deploy_health_path }}
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
            :compact="! $nested"
            section-id="pipeline-advisor-overview"
            :title="__('Pipeline review')"
            :description="__('Apply the recommended fix for each flagged step or hook below.')"
        />
    @endif

    <div class="flex flex-wrap gap-2 border-t border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
        <button type="button" wire:click="setPipelineTab('steps')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Edit build steps') }}</button>
        <button type="button" wire:click="setPipelineTab('steps')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Edit hooks') }}</button>
        <button type="button" wire:click="setPipelineTab('rollout')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Edit rollout') }}</button>
    </div>
</section>

@if ($site->usesDockerRuntime() || $site->usesKubernetesRuntime())
    @include('livewire.sites.partials.pipeline._runtime-artifacts')
@endif
