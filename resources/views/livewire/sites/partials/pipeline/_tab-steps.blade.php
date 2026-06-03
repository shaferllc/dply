@php
    $functionsHost = $functionsHost ?? $server->hostCapabilities()->supportsFunctionDeploy();
    $orderedSteps = ($editingDeploySteps ?? collect())->sortBy('sort_order')->values();
    $stepCount = $orderedSteps->count();
    $hookCount = $editingDeployPipeline?->hooks?->count() ?? $site->deployHooks->count();
    $editingPipeline = $editingDeployPipeline ?? null;
    $isActiveDeployPipeline = $editingPipeline?->isActiveFor($site) ?? true;
    $pipelineCount = $site->deployPipelines->count();
    $timelineSplit = $pipelineTimelineSplit ?? [
        'prefix' => [],
        'buildBlocks' => [],
        'mid' => [],
        'releaseBlocks' => [],
        'suffix' => [],
    ];
    $buildPalette = collect($pipelinePalette ?? [])->where('phase', 'build')->values();
    $releasePalette = collect($pipelinePalette ?? [])->where('phase', 'release')->values();

    $hooksBeforeClone = [];
    $hooksAfterClone = [];
    $hooksBeforeActivate = [];
    $hooksAfterActivate = [];
    $showCloneAnchor = false;
    $showActivateAnchor = false;

    foreach ($timelineSplit['prefix'] as $item) {
        if (($item['type'] ?? '') === 'anchor' && ($item['key'] ?? '') === 'clone') {
            $showCloneAnchor = true;

            continue;
        }
        if (($item['type'] ?? '') === 'hook' && ($item['hook']->anchor ?? '') === \App\Models\SiteDeployHook::ANCHOR_BEFORE_CLONE) {
            $hooksBeforeClone[] = $item;
        } elseif (($item['type'] ?? '') === 'hook') {
            $hooksAfterClone[] = $item;
        }
    }

    foreach ($timelineSplit['mid'] as $item) {
        if (($item['type'] ?? '') === 'anchor' && ($item['key'] ?? '') === 'activate') {
            $showActivateAnchor = true;

            continue;
        }
        if (($item['type'] ?? '') === 'hook') {
            $hooksBeforeActivate[] = $item;
        }
    }

    foreach ($timelineSplit['suffix'] as $item) {
        if (($item['type'] ?? '') === 'hook') {
            $hooksAfterActivate[] = $item;
        }
    }
@endphp

@if (! $functionsHost && $editingPipeline)
    {{-- deployPipelineWorkspace() is registered from the main app.js bundle (before Alpine.start). --}}
    @vite(['resources/css/deploy-pipeline.css'])

    <section
        class="dply-card overflow-hidden"
        x-data="deployPipelineWorkspace()"
    >
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-sky-50 text-sky-700 ring-sky-200">
                    <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Build') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Pipeline') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('The pipeline runs top to bottom in four phases. Drag steps into Build or Release; drag hooks onto dashed slots in any phase.') }}
                </p>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if (method_exists($this, 'optimizePipeline'))
                    <button
                        type="button"
                        wire:click="optimizePipeline"
                        wire:loading.attr="disabled"
                        wire:target="optimizePipeline"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-indigo-100 disabled:opacity-60"
                        title="{{ __('Read package.json / composer.json on the server and add every deploy step the repo needs.') }}"
                    >
                        <x-heroicon-o-sparkles class="h-3.5 w-3.5" wire:loading.remove wire:target="optimizePipeline" />
                        <span wire:loading wire:target="optimizePipeline" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                        <span wire:loading.remove wire:target="optimizePipeline">{{ __('Optimize pipeline') }}</span>
                        <span wire:loading wire:target="optimizePipeline">{{ __('Scanning…') }}</span>
                    </button>
                @endif
                <button
                    type="button"
                    x-on:click="$dispatch('open-modal', 'pipeline-share')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                    title="{{ __('Export or import this pipeline') }}"
                >
                    <x-heroicon-o-share class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Share') }}
                </button>
            </div>
        </div>

        {{-- Pipeline review lives on the Overview subtab — not duplicated here. --}}

        <div class="space-y-6 border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-8">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Pipelines') }}</span>
                @foreach ($site->deployPipelines as $pipeline)
                    @php $pipelineSelected = (string) $editingPipeline->id === (string) $pipeline->id; @endphp
                    <button
                        type="button"
                        wire:click="setEditingPipeline('{{ $pipeline->id }}')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition',
                            'border-brand-ink bg-brand-ink text-brand-cream shadow-sm' => $pipelineSelected,
                            'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $pipelineSelected,
                        ])
                    >
                        {{ $pipeline->name }}
                        @if ($pipeline->isActiveFor($site))
                            <span @class([
                                'rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide',
                                'bg-brand-cream/25 text-brand-cream ring-1 ring-brand-cream/30' => $pipelineSelected,
                                'bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/30' => ! $pipelineSelected,
                            ])>{{ __('Deploy') }}</span>
                        @endif
                    </button>
                @endforeach
                <button
                    type="button"
                    wire:click="openCreatePipelineForm"
                    class="inline-flex items-center gap-1 rounded-full border border-dashed border-brand-sage/50 px-3 py-1.5 text-xs font-semibold text-brand-forest hover:bg-brand-sage/5"
                >
                    <x-heroicon-m-plus class="h-3.5 w-3.5" />
                    {{ __('New pipeline') }}
                </button>
            </div>

            {{-- Branch→pipeline routing only matters with 2+ pipelines; with a
                 single pipeline it always runs regardless, so hide the noise. --}}
            @if ($pipelineCount > 1)
            <form wire:submit="saveEditingPipelineBranches" class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                <label for="editing_pipeline_branches" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Git branches') }}</label>
                <p class="mb-2 text-sm text-brand-moss">
                    {{ __('Comma-separated branch names or patterns (e.g. main, develop, release/*). When the site deploys that branch, this pipeline is used—even if another pipeline is marked Deploy in the UI.') }}
                </p>
                <div class="flex flex-wrap items-end gap-3">
                    <input
                        type="text"
                        id="editing_pipeline_branches"
                        wire:model="editing_pipeline_branches"
                        class="min-w-[14rem] flex-1 rounded-lg border border-brand-ink/15 px-3 py-2 text-sm"
                        placeholder="{{ __('main, develop, staging/*') }}"
                    />
                    <x-secondary-button type="submit" wire:loading.attr="disabled" wire:target="saveEditingPipelineBranches">
                        <span wire:loading.remove wire:target="saveEditingPipelineBranches">{{ __('Save branches') }}</span>
                        <span wire:loading wire:target="saveEditingPipelineBranches">{{ __('Saving…') }}</span>
                    </x-secondary-button>
                </div>
                <x-input-error :messages="$errors->get('editing_pipeline_branches')" class="mt-1" />
            </form>
            @endif

            <div class="flex flex-wrap items-center gap-2">
                @unless ($isActiveDeployPipeline)
                    <button
                        type="button"
                        wire:click="activateEditingDeployPipeline"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest"
                    >
                        <x-heroicon-m-rocket-launch class="h-3.5 w-3.5" />
                        {{ __('Use for deploys') }}
                    </button>
                @else
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-brand-forest">
                        <x-heroicon-m-check-circle class="h-4 w-4" />
                        {{ __('This pipeline runs on deploy') }}
                    </span>
                @endunless
                <button type="button" wire:click="duplicateEditingDeployPipeline" class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Duplicate') }}</button>
                @if ($pipelineCount > 1)
                    <button type="button" wire:click="openDeletePipelineModal('{{ $editingPipeline->id }}')" class="text-xs font-semibold text-red-700 hover:underline">{{ __('Delete pipeline') }}</button>
                @endif
            </div>

            @if ($show_create_pipeline_form)
                <form wire:submit="createDeployPipeline" class="flex flex-wrap items-end gap-3 rounded-2xl border border-brand-ink/10 bg-white p-4">
                    <div class="min-w-[12rem] flex-1">
                        <label for="new_pipeline_name" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Pipeline name') }}</label>
                        <input type="text" id="new_pipeline_name" wire:model="new_pipeline_name" class="w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm" placeholder="{{ __('Staging build') }}" />
                        <x-input-error :messages="$errors->get('new_pipeline_name')" class="mt-1" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-brand-ink">
                        <input type="checkbox" wire:model="duplicate_current_on_create" class="rounded border-brand-ink/30" />
                        {{ __('Copy steps from current') }}
                    </label>
                    <x-primary-button type="submit">{{ __('Create') }}</x-primary-button>
                    <button type="button" wire:click="closeCreatePipelineForm" class="text-sm font-semibold text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                </form>
            @endif
        </div>

        @if (($pipelineStarters ?? []) !== [])
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Starter pipelines') }}</p>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('Full recipes: deploy strategy, health check defaults, steps, and hooks (safe Laravel). Replaces the target pipeline.') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($pipelineStarters as $starter)
                        <button
                            type="button"
                            wire:click="openApplyStarterModal('{{ $starter['key'] }}')"
                            title="{{ $starter['description'] }}"
                            class="inline-flex items-center gap-1.5 rounded-full border border-brand-sage/40 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:border-brand-forest/40 hover:bg-brand-sage/10"
                        >
                            <x-dynamic-component :component="$starter['icon']" class="h-3.5 w-3.5 text-brand-forest" />
                            {{ $starter['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($pipelineSafetyBundleVisible ?? false)
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Safety presets') }}</p>
                @php $safetyBundle = ($pipelineSafetyBundles ?? [])[\App\Support\Sites\DeployPipelineSafetyPresets::BUNDLE_LARAVEL_V1] ?? null; @endphp
                @if ($safetyBundle)
                    <p class="mt-1 text-sm text-brand-moss">{{ $safetyBundle['description'] }}</p>
                    <button
                        type="button"
                        wire:click="applyLaravelSafetyPresetBundle"
                        wire:loading.attr="disabled"
                        wire:target="applyLaravelSafetyPresetBundle"
                        class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100"
                    >
                        <x-heroicon-m-shield-check class="h-3.5 w-3.5" wire:loading.remove wire:target="applyLaravelSafetyPresetBundle" />
                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="applyLaravelSafetyPresetBundle" />
                        <span wire:loading.remove wire:target="applyLaravelSafetyPresetBundle">{{ $safetyBundle['label'] }}</span>
                        <span wire:loading wire:target="applyLaravelSafetyPresetBundle">{{ __('Applying…') }}</span>
                    </button>
                    <p class="mt-2 text-xs text-brand-moss">{{ __('Adds maintenance down/up hooks, migrate pretend, and a pre-migrate DB snapshot step. Add Migrate when you are ready to apply schema changes.') }}</p>
                @endif
            </div>
        @endif

        @if (($deployPipelineTemplates ?? []) !== [])
            <details class="group border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <summary class="cursor-pointer list-none text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist marker:content-none">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-m-chevron-right class="h-3.5 w-3.5 transition group-open:rotate-90" />
                        {{ __('Steps only (advanced)') }}
                    </span>
                </summary>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Replace steps on this pipeline without changing deploy strategy or health check settings.') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($deployPipelineTemplates as $template)
                        <button
                            type="button"
                            wire:click="openApplyTemplateModal('{{ $template['key'] }}')"
                            title="{{ $template['description'] }}"
                            class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:border-brand-sage/40 hover:bg-brand-sage/5"
                        >
                            <x-heroicon-m-document-duplicate class="h-3.5 w-3.5 text-brand-forest" />
                            {{ $template['label'] }}
                        </button>
                    @endforeach
                </div>
            </details>
        @endif

        {{-- Share pipeline moved to a modal opened from the pipeline header. --}}
        <div class="space-y-4 px-6 pt-4 sm:px-8">
            @include('livewire.sites.partials.pipeline._pipeline-quick-commands')
        </div>

        <div class="space-y-6 p-6 sm:p-8">
            <div class="relative">
            <div
                class="dply-pipeline-timeline space-y-3 rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:p-5"
                role="list"
                aria-label="{{ __('Deploy pipeline order') }}"
                wire:key="pipeline-timeline-{{ $editingPipeline->id }}-{{ $stepCount }}-{{ $hookCount }}"
                wire:loading.class.delay="opacity-50 pointer-events-none"
                wire:target="addDeployPipelineStep,saveDeployPipelineStep,updateDeployPipelineStep,addDeployPipelineStepFromPalette,reorderDeployPipelineBuildSteps,reorderDeployPipelineReleaseSteps,addDeployPipelineHookFromPalette,saveDeployPipelineHook,addDeployPipelineHook,deleteDeployPipelineStep,appendQuickCommands,confirmAddDuplicatePipelineStep,openApplyStarterModal,confirmApplyStarterPipeline,confirmApplyDeployPipelineTemplate,applyLaravelSafetyPresetBundle"
            >
                <div class="hidden flex-wrap items-center gap-2 pb-1 sm:flex" aria-hidden="true">
                    <span class="rounded-full bg-stone-200/80 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-stone-800">1 {{ __('Clone') }}</span>
                    <x-heroicon-m-chevron-right class="h-3.5 w-3.5 text-brand-mist" />
                    <span class="rounded-full bg-sky-200/80 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-900">2 {{ __('Build') }}</span>
                    <x-heroicon-m-chevron-right class="h-3.5 w-3.5 text-brand-mist" />
                    <span class="rounded-full bg-brand-sage/30 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-forest">3 {{ __('Activate') }}</span>
                    <x-heroicon-m-chevron-right class="h-3.5 w-3.5 text-brand-mist" />
                    <span class="rounded-full bg-emerald-200/80 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-900">4 {{ __('Release') }}</span>
                </div>

                <x-pipeline-timeline-section
                    :step="1"
                    :title="__('Clone & fetch')"
                    :description="__('Git checkout into a new release folder. Optional hooks run on the server before or right after clone.')"
                    tone="prepare"
                >
                    @include('livewire.sites.partials.pipeline._timeline-hook-drop-zone', [
                        'anchor' => 'before_clone',
                        'items' => $hooksBeforeClone,
                        'empty' => __('Hook'),
                    ])
                    @if ($showCloneAnchor)
                        @include('livewire.sites.partials.pipeline._timeline-flow-connector')
                        @include('livewire.sites.partials.pipeline._timeline-item', ['item' => ['type' => 'anchor', 'key' => 'clone']])
                        @include('livewire.sites.partials.pipeline._timeline-flow-connector')
                    @endif
                    @include('livewire.sites.partials.pipeline._timeline-hook-drop-zone', [
                        'anchor' => 'after_clone',
                        'items' => $hooksAfterClone,
                        'empty' => __('Hook'),
                    ])
                </x-pipeline-timeline-section>

                <x-pipeline-timeline-section
                    :step="2"
                    :title="__('Build')"
                    :description="__('Install dependencies and compile assets in the new release — before the site goes live.')"
                    tone="build"
                >
                    <div
                        x-ref="buildSortZone"
                        @class([
                            'dply-pipeline-step-row inline-flex min-h-[2.75rem] min-w-0 max-w-full flex-nowrap items-center gap-2 overflow-x-auto rounded-xl px-1 py-1 transition-colors',
                            'border border-dashed border-sky-300/60 bg-sky-50/50' => count($timelineSplit['buildBlocks']) === 0,
                            'border border-transparent' => count($timelineSplit['buildBlocks']) > 0,
                        ])
                        data-pipeline-drop-zone="build"
                    >
                        @php $buildBlockCount = count($timelineSplit['buildBlocks']); @endphp
                        @forelse ($timelineSplit['buildBlocks'] as $blockIndex => $block)
                            @include('livewire.sites.partials.pipeline._timeline-step-block', ['block' => $block])
                            @if ($blockIndex < $buildBlockCount - 1)
                                @include('livewire.sites.partials.pipeline._timeline-flow-connector')
                            @endif
                        @empty
                            <span class="px-2 text-xs text-brand-moss">{{ __('Drop build steps from the palette below') }}</span>
                        @endforelse
                    </div>
                </x-pipeline-timeline-section>

                <x-pipeline-timeline-section
                    :step="3"
                    :title="__('Activate')"
                    :description="__('Flip traffic to the new release (symlink for zero-downtime). Hooks can run just before the swap.')"
                    tone="activate"
                >
                    @include('livewire.sites.partials.pipeline._timeline-hook-drop-zone', [
                        'anchor' => 'before_activate',
                        'items' => $hooksBeforeActivate,
                        'empty' => __('Hook'),
                    ])
                    @if ($showActivateAnchor)
                        @include('livewire.sites.partials.pipeline._timeline-flow-connector')
                        @include('livewire.sites.partials.pipeline._timeline-item', ['item' => ['type' => 'anchor', 'key' => 'activate']])
                    @endif
                </x-pipeline-timeline-section>

                <x-pipeline-timeline-section
                    :step="4"
                    :title="__('Release')"
                    :description="__('Commands on the live path after activate — migrations, cache warmers, etc.')"
                    tone="release"
                >
                    <div class="flex min-w-0 flex-col gap-2 lg:flex-row lg:items-center lg:gap-2">
                        <div
                            x-ref="releaseSortZone"
                            @class([
                                'dply-pipeline-step-row inline-flex min-h-[2.75rem] min-w-0 flex-1 flex-nowrap items-center gap-2 overflow-x-auto rounded-xl px-1 py-1 transition-colors',
                                'border border-dashed border-emerald-300/60 bg-emerald-50/50' => count($timelineSplit['releaseBlocks']) === 0,
                                'border border-transparent' => count($timelineSplit['releaseBlocks']) > 0,
                            ])
                            data-pipeline-drop-zone="release"
                        >
                            @php $releaseBlockCount = count($timelineSplit['releaseBlocks']); @endphp
                            @forelse ($timelineSplit['releaseBlocks'] as $blockIndex => $block)
                                @php $isLastReleaseStep = $blockIndex === $releaseBlockCount - 1; @endphp
                                @include('livewire.sites.partials.pipeline._timeline-step-block', [
                                    'block' => $block,
                                    'showAfterStepHookZone' => ! $isLastReleaseStep || count($block['hooks']) > 0,
                                ])
                                @if ($blockIndex < $releaseBlockCount - 1)
                                    @include('livewire.sites.partials.pipeline._timeline-flow-connector')
                                @endif
                            @empty
                                <span class="px-2 text-xs text-brand-moss">{{ __('Drop release steps from the palette below') }}</span>
                            @endforelse
                        </div>
                        <div class="dply-pipeline-release-tail inline-flex shrink-0 items-center gap-2">
                            @if ($releaseBlockCount > 0)
                                @include('livewire.sites.partials.pipeline._timeline-flow-connector')
                            @endif
                            @include('livewire.sites.partials.pipeline._timeline-hook-drop-zone', [
                                'anchor' => 'after_activate',
                                'items' => $hooksAfterActivate,
                                'empty' => count($hooksAfterActivate) === 0
                                    ? (\App\Models\SiteDeployHook::anchorLabels()['after_activate'] ?? __('After activate'))
                                    : null,
                            ])
                        </div>
                    </div>
                </x-pipeline-timeline-section>

                @if ($stepCount === 0 && $hookCount === 0)
                    <p class="rounded-xl border border-dashed border-brand-ink/15 bg-white/60 px-4 py-3 text-sm text-brand-moss">
                        {{ __('Add build steps and hooks from the palettes below.') }}
                    </p>
                @endif
            </div>
            @include('livewire.sites.partials.pipeline._pipeline-timeline-busy-overlay')
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="openAddPipelineStepForm"
                            class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <x-heroicon-m-plus class="h-3.5 w-3.5" />
                            {{ __('Add step') }}
                        </button>
                        <button
                            type="button"
                            wire:click="openAddPipelineStepForm('custom', 'build')"
                            class="inline-flex items-center gap-1.5 rounded-full border border-dashed border-brand-sage/50 px-3 py-1.5 text-xs font-semibold text-brand-forest hover:bg-brand-sage/5"
                        >
                            <x-heroicon-m-code-bracket class="h-3.5 w-3.5" />
                            {{ __('Custom command') }}
                        </button>
                        <button
                            type="button"
                            wire:click="setPipelineTab('reference')"
                            class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-book-open class="h-3.5 w-3.5" />
                            {{ __('Browse all steps') }}
                        </button>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Build palette') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Dependency installs and asset builds — runs in phase 2 above.') }}</p>
                        <div x-ref="buildPalette" class="mt-3 flex flex-wrap gap-2">
                            @forelse ($buildPalette as $entry)
                                @include('livewire.sites.partials.pipeline._step-palette-item', ['entry' => $entry])
                            @empty
                                <p class="text-xs text-brand-moss">{{ __('No build presets for this runtime — use Custom command.') }}</p>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Release palette') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Migrations, cache, and workers on the live path — phase 4 above.') }}</p>
                        <div x-ref="releasePalette" class="mt-3 flex flex-wrap gap-2">
                            @forelse ($releasePalette as $entry)
                                @include('livewire.sites.partials.pipeline._step-palette-item', ['entry' => $entry])
                            @empty
                                <p class="text-xs text-brand-moss">{{ __('No release presets for this runtime — use Custom (release).') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>
                <div class="space-y-4">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Hook types') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Drag onto any dashed hook slot in the timeline.') }}</p>
                        <div x-ref="hookPalette" class="mt-3 flex flex-wrap gap-2">
                            @foreach (config('site_deploy_pipeline.hook_palette', []) as $entry)
                                @include('livewire.sites.partials.pipeline._hook-palette-item', ['entry' => $entry])
                            @endforeach
                        </div>
                    </div>
                    @if (($pipelineHookPresets ?? []) !== [])
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Common hook shortcuts') }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Opens the configure dialog with script and timing prefilled.') }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($pipelineHookPresets as $preset)
                                    <button
                                        type="button"
                                        wire:click="addDeployPipelineHookFromPreset(@js($preset))"
                                        class="inline-flex items-center gap-1.5 rounded-full border border-amber-200/80 bg-amber-50/80 px-3 py-1.5 text-xs font-semibold text-amber-950 shadow-sm hover:bg-amber-100/80"
                                    >
                                        <x-dynamic-component :component="$preset['icon'] ?? 'heroicon-o-bolt'" class="h-3.5 w-3.5 shrink-0" />
                                        {{ __($preset['label']) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Full step/hook catalog lives on the Reference subtab; the
                 post-deploy command lives in the deploy recipe settings — not
                 duplicated here. --}}
        </div>
    </section>

    @include('livewire.sites.partials.pipeline._pipeline-modals')
@endif
