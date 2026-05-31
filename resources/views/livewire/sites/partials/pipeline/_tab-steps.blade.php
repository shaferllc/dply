@php
    $functionsHost = $functionsHost ?? $server->hostCapabilities()->supportsFunctionDeploy();
    $orderedSteps = ($editingDeploySteps ?? collect())->sortBy('sort_order')->values();
    $stepCount = $orderedSteps->count();
    $hookCount = $editingDeployPipeline?->hooks?->count() ?? $site->deployHooks->count();
    $editingPipeline = $editingDeployPipeline ?? null;
    $isActiveDeployPipeline = $editingPipeline?->isActiveFor($site) ?? true;
    $pipelineCount = $site->deployPipelines->count();
    $timeline = $pipelineTimeline ?? [];
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
    @vite(['resources/js/deploy-pipeline-dnd.js'])

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
                    {{ __('Drag build steps onto the build or release areas. Drag Shell, Webhook, or Notification onto any dashed hook slot in the timeline.') }}
                </p>
                </div>
            </div>
        </div>

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

        @if (($deployPipelineTemplates ?? []) !== [])
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Dply templates') }}</p>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Replace all steps on this pipeline with a starter recipe.') }}</p>
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
            </div>
        @endif

        <div class="space-y-6 p-6 sm:p-8">
            <div
                class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-5 sm:px-6"
                role="list"
                aria-label="{{ __('Deploy pipeline order') }}"
            >
                <div class="flex flex-wrap items-center gap-2">
                    @include('livewire.sites.partials.pipeline._timeline-hook-drop-zone', [
                        'anchor' => 'before_clone',
                        'items' => $hooksBeforeClone,
                        'empty' => __('Drop hook here'),
                    ])
                    @include('livewire.sites.partials.pipeline._timeline-chevron')

                    @if ($showCloneAnchor)
                        @include('livewire.sites.partials.pipeline._timeline-item', ['item' => ['type' => 'anchor', 'key' => 'clone']])
                    @endif

                    @include('livewire.sites.partials.pipeline._timeline-hook-drop-zone', [
                        'anchor' => 'after_clone',
                        'items' => $hooksAfterClone,
                        'empty' => __('Drop hook here'),
                    ])
                    @include('livewire.sites.partials.pipeline._timeline-chevron')

                    <div
                        x-ref="buildSortZone"
                        @class([
                            'inline-flex min-h-[2.75rem] min-w-[6rem] flex-wrap items-center gap-2 rounded-xl px-1 py-1 transition-colors',
                            'border border-dashed border-sky-300/50 bg-sky-50/40' => count($timelineSplit['buildBlocks']) === 0,
                            'border border-transparent' => count($timelineSplit['buildBlocks']) > 0,
                        ])
                        data-pipeline-drop-zone="build"
                    >
                        @forelse ($timelineSplit['buildBlocks'] as $block)
                            @include('livewire.sites.partials.pipeline._timeline-step-block', ['block' => $block])
                        @empty
                            <span class="px-2 text-xs text-brand-moss">{{ __('Drop build steps here') }}</span>
                        @endforelse
                    </div>

                    @include('livewire.sites.partials.pipeline._timeline-hook-drop-zone', [
                        'anchor' => 'before_activate',
                        'items' => $hooksBeforeActivate,
                        'empty' => __('Drop hook here'),
                    ])
                    @include('livewire.sites.partials.pipeline._timeline-chevron')

                    @if ($showActivateAnchor)
                        @include('livewire.sites.partials.pipeline._timeline-item', ['item' => ['type' => 'anchor', 'key' => 'activate']])
                    @endif

                    <div
                        x-ref="releaseSortZone"
                        @class([
                            'inline-flex min-h-[2.75rem] min-w-[6rem] flex-wrap items-center gap-2 rounded-xl px-1 py-1 transition-colors',
                            'border border-dashed border-emerald-300/50 bg-emerald-50/40' => count($timelineSplit['releaseBlocks']) === 0,
                            'border border-transparent' => count($timelineSplit['releaseBlocks']) > 0,
                        ])
                        data-pipeline-drop-zone="release"
                    >
                        @forelse ($timelineSplit['releaseBlocks'] as $block)
                            @include('livewire.sites.partials.pipeline._timeline-step-block', ['block' => $block])
                        @empty
                            <span class="px-2 text-xs text-brand-moss">{{ __('Drop release steps here (e.g. migrate)') }}</span>
                        @endforelse
                    </div>

                    @include('livewire.sites.partials.pipeline._timeline-hook-drop-zone', [
                        'anchor' => 'after_activate',
                        'items' => $hooksAfterActivate,
                        'empty' => __('Drop hook here'),
                    ])
                </div>

                @if ($stepCount === 0 && $hookCount === 0)
                    <p class="mt-4 text-sm text-brand-moss">{{ __('Add build steps and hooks from the palettes below.') }}</p>
                @endif
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
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Build palette') }}</p>
                        <div x-ref="buildPalette" class="mt-3 flex flex-wrap gap-2">
                            @foreach ($buildPalette as $entry)
                                <div
                                    data-palette-type="{{ $entry['type'] }}"
                                    data-palette-phase="build"
                                    class="inline-flex max-w-full select-none items-stretch rounded-full border border-sky-300/70 bg-white shadow-sm ring-1 ring-sky-200/40"
                                    title="{{ __('Drag by the handle before activate, or click the label to append') }}"
                                >
                                    <span
                                        data-palette-drag-handle
                                        class="dply-palette-drag-handle inline-flex h-9 w-9 shrink-0 cursor-grab items-center justify-center rounded-l-full border-r border-sky-200/80 bg-sky-50 text-sky-800/70 active:cursor-grabbing"
                                        aria-hidden="true"
                                    >
                                        <x-heroicon-m-bars-3 class="h-4 w-4" />
                                    </span>
                                    <button
                                        type="button"
                                        wire:click="addDeployPipelineStepFromPalette('{{ $entry['type'] }}', null, 'build')"
                                        data-pipeline-no-drag
                                        class="inline-flex min-h-9 items-center gap-1.5 rounded-r-full px-3 py-2 text-xs font-semibold text-sky-900 hover:bg-sky-50"
                                    >
                                        <x-dynamic-component :component="$entry['icon'] ?? 'heroicon-o-plus'" class="h-4 w-4 shrink-0" />
                                        {{ __($entry['label']) }}
                                    </button>
                                    @include('livewire.sites.partials.pipeline._timeline-chevron')
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Release palette') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Runs after activate on the live release path.') }}</p>
                        <div x-ref="releasePalette" class="mt-3 flex flex-wrap gap-2">
                            @foreach ($releasePalette as $entry)
                                <div
                                    data-palette-type="{{ $entry['type'] }}"
                                    data-palette-phase="release"
                                    class="inline-flex max-w-full select-none items-stretch rounded-full border border-emerald-300/70 bg-white shadow-sm ring-1 ring-emerald-200/40"
                                    title="{{ __('Drag by the handle after activate, or click the label to append') }}"
                                >
                                    <span
                                        data-palette-drag-handle
                                        class="dply-palette-drag-handle inline-flex h-9 w-9 shrink-0 cursor-grab items-center justify-center rounded-l-full border-r border-emerald-200/80 bg-emerald-50 text-emerald-800/70 active:cursor-grabbing"
                                        aria-hidden="true"
                                    >
                                        <x-heroicon-m-bars-3 class="h-4 w-4" />
                                    </span>
                                    <button
                                        type="button"
                                        wire:click="addDeployPipelineStepFromPalette('{{ $entry['type'] }}', null, 'release')"
                                        data-pipeline-no-drag
                                        class="inline-flex min-h-9 items-center gap-1.5 rounded-r-full px-3 py-2 text-xs font-semibold text-emerald-900 hover:bg-emerald-50"
                                    >
                                        <x-dynamic-component :component="$entry['icon'] ?? 'heroicon-o-plus'" class="h-4 w-4 shrink-0" />
                                        {{ __($entry['label']) }}
                                    </button>
                                    @include('livewire.sites.partials.pipeline._timeline-chevron')
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Add hooks') }}</p>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Drag onto any dashed hook slot in the timeline — placement sets when it runs. Finish setup in the dialog that opens.') }}</p>
                    <div x-ref="hookPalette" class="mt-3 flex flex-wrap gap-3">
                        @foreach (config('site_deploy_pipeline.hook_palette', []) as $entry)
                            @include('livewire.sites.partials.pipeline._hook-palette-item', ['entry' => $entry])
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/50 p-4 sm:p-5">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Post-deploy command') }}</p>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Optional shell script after all pipeline pills finish.') }}</p>
                <div class="mt-4">
                    <textarea id="pipeline_post_deploy_command" wire:model="post_deploy_command" rows="3" class="w-full rounded-lg border border-brand-ink/15 font-mono text-sm shadow-sm"></textarea>
                    <p class="mt-2 text-xs text-brand-moss">{{ __('Use the save bar at the bottom of the page when you are ready to persist changes.') }}</p>
                </div>
            </div>
        </div>
    </section>

    @include('livewire.sites.partials.pipeline._pipeline-modals')
@endif
