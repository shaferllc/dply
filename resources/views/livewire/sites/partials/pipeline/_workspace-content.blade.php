@php
    $card = 'dply-card overflow-hidden';
    $functionsHost = $functionsHost ?? $server->hostCapabilities()->supportsFunctionDeploy();
    $stepCount = $site->deploySteps->count();
    $hookCount = $site->deployHooks->count();
    $strategyLabel = $site->deploy_strategy === 'atomic' ? __('Zero downtime (atomic)') : __('Simple (in-place)');
@endphp

<section class="{{ $card }}">
    <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-adjustments-horizontal class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Pipeline') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Build, hooks & rollout') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Configure build steps, deploy hooks, zero-downtime rollout, and post-activate checks. Connect the repository under Repository; run deploys from Deployments.') }}
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                    <span class="inline-flex items-center gap-1">
                        <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                        {{ __('strategy: :label', ['label' => $strategyLabel]) }}
                    </span>
                    <span class="text-brand-mist/60">·</span>
                    <span>{{ trans_choice('{0} no build steps|{1} :count build step|[2,*] :count build steps', $stepCount, ['count' => $stepCount]) }}</span>
                    <span class="text-brand-mist/60">·</span>
                    <span>{{ trans_choice('{0} no hooks|{1} :count hook|[2,*] :count hooks', $hookCount, ['count' => $hookCount]) }}</span>
                </div>
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <a
                href="{{ route('sites.deployments.index', [$server, $site]) }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Open deployments') }}
            </a>
        </div>
    </div>
</section>

<x-server-workspace-tablist :aria-label="__('Pipeline sections')" class="mt-6">
    @foreach ($pipelineTabs as $tabId => $tabLabel)
        <x-server-workspace-tab
            id="pipeline-tab-{{ $tabId }}"
            :active="$pipelineTab === $tabId"
            :icon="$pipelineTabIcons[$tabId] ?? 'heroicon-o-adjustments-horizontal'"
            wire:click="setPipelineTab('{{ $tabId }}')"
        >{{ __($tabLabel) }}</x-server-workspace-tab>
    @endforeach
</x-server-workspace-tablist>

<div class="mt-6 space-y-6" wire:key="pipeline-panel-{{ $pipelineTab }}">
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
