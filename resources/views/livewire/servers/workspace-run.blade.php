@php
    $opsReady = $server->isReady() && $server->ssh_private_key;
    $recipeCount = $server->recipes->count();
@endphp

<x-server-workspace-layout
    :server="$server"
    active="run"
    :title="__('Run')"
    :description="__('Run server-level commands. Saved commands, ad-hoc shell, and library presets all in one place. Site deploys live on each site’s page.')"
    hide-hero
>
    @include('livewire.servers.partials.run-output-panel')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($container_scope_id !== '')
        <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-xl border border-brand-gold/40 bg-brand-cream/50 px-3 py-2">
            <p
                class="min-w-0 flex-1 text-xs leading-snug text-brand-moss"
                title="{{ __('Ad-hoc commands and saved recipes run inside this container via docker exec. Host-level marketplace presets (docker ps, prune, etc.) still target the VM unless you save a container-inner recipe.') }}"
            >
                <span class="font-semibold text-brand-ink">{{ __('Container scope') }}</span>
                <span class="text-brand-mist">·</span>
                <span class="font-mono text-brand-forest">{{ $container_scope_name }}</span>
                <span class="text-brand-mist">·</span>
                {{ __('commands run inside this container via docker exec.') }}
            </p>
            <button
                type="button"
                wire:click="clearContainerScope"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ __('Clear scope') }}
            </button>
        </div>
    @endif

    @if ($opsReady)
        <div class="space-y-4">
            <div class="dply-card min-w-0 overflow-hidden p-0">
                {{-- Single-row identity + actions (replaces layout hero + blue banner) --}}
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <h2
                        class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink"
                        title="{{ __('Saved recipes and one-off shell on this server. Site deploys live on each site’s page.') }}"
                    >
                        <x-heroicon-o-play class="h-4 w-4 text-brand-forest" aria-hidden="true" />
                        {{ __('Run') }}
                    </h2>

                    <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>

                    <p class="min-w-0 flex-1 text-[11px] text-brand-mist">
                        {{ __(':count saved on this server', ['count' => $recipeCount]) }}
                        ·
                        <a href="{{ route('servers.sites', $server) }}" wire:navigate class="font-medium text-brand-moss underline-offset-2 hover:text-brand-ink hover:underline">{{ __('Sites') }}</a>
                        @if ($server->workspace)
                            @feature('surface.projects')
                                ·
                                <a href="{{ route('projects.delivery', $server->workspace) }}" wire:navigate class="font-medium text-brand-moss underline-offset-2 hover:text-brand-ink hover:underline">{{ __('Project delivery') }}</a>
                            @endfeature
                        @endif
                    </p>

                    <div class="flex shrink-0 flex-row flex-nowrap items-center gap-1.5">
                        <button
                            type="button"
                            wire:click="openLibrary"
                            class="inline-flex items-center gap-1 rounded-lg bg-brand-ink px-2 py-1 text-[11px] font-semibold text-white shadow-sm hover:bg-brand-ink/90 focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="3" width="14" height="14" rx="2"/>
                                <path d="M3 8h14"/>
                                <path d="M8 3v14"/>
                            </svg>
                            {{ __('Browse library') }}
                            <span class="rounded-full bg-white/15 px-1.5 text-[10px] font-medium">
                                {{ $libraryTotals['marketplace'] + $libraryTotals['organization'] }}
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="startNewRecipe"
                            class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M10 4v12"/>
                                <path d="M4 10h12"/>
                            </svg>
                            {{ __('Write your own') }}
                        </button>
                    </div>
                </div>

                {{-- Library --}}
                <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4">
                    <div class="mb-2 flex items-center gap-1.5">
                        <x-heroicon-o-rectangle-stack class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Library on this server') }}</p>
                    </div>

                    @if ($server->recipes->isEmpty())
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-3 text-center text-xs text-brand-moss">
                            <span class="font-medium text-brand-ink">{{ __('No saved commands yet.') }}</span>
                            {{ __('Browse the library for a preset or script — or write your own.') }}
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                            @foreach ($server->recipes as $rec)
                                <li class="flex flex-col gap-2 px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex min-w-0 flex-wrap items-baseline gap-x-2">
                                        <p class="truncate text-sm font-medium text-brand-ink">{{ $rec->name }}</p>
                                        <p class="text-[11px] text-brand-moss">
                                            {{ __('Updated :when', ['when' => $rec->updated_at?->diffForHumans() ?? '—']) }}
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap gap-1.5 text-[11px] font-medium">
                                        @php
                                            $deleteCall = "openConfirmActionModal('deleteRecipe', ['".$rec->id."'], '".addslashes(__('Delete saved command'))."', '".addslashes(__('Delete saved command?'))."', '".addslashes(__('Delete'))."', true)";
                                        @endphp

                                        <button
                                            type="button"
                                            wire:click="runRecipe('{{ $rec->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="runRecipe('{{ $rec->id }}')"
                                            @disabled($isRunning)
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-sage/40 bg-brand-sage/10 px-2 py-0.5 text-brand-sage hover:bg-brand-sage/20 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <span wire:loading.remove wire:target="runRecipe('{{ $rec->id }}')" class="inline-flex items-center gap-1">
                                                <x-heroicon-o-bolt class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Run') }}
                                            </span>
                                            <span wire:loading wire:target="runRecipe('{{ $rec->id }}')" class="inline-flex items-center gap-1.5">
                                                <span class="inline-block size-3 shrink-0 animate-spin rounded-full border-2 border-brand-sage/40 border-t-brand-sage" aria-hidden="true"></span>
                                                {{ __('Running…') }}
                                            </span>
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="editRecipe('{{ $rec->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="editRecipe('{{ $rec->id }}')"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2 py-0.5 text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <span wire:loading.remove wire:target="editRecipe('{{ $rec->id }}')" class="inline-flex items-center gap-1">
                                                <x-heroicon-o-pencil-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Edit') }}
                                            </span>
                                            <span wire:loading wire:target="editRecipe('{{ $rec->id }}')" class="inline-flex items-center gap-1.5">
                                                <span class="inline-block size-3 shrink-0 animate-spin rounded-full border-2 border-brand-ink/25 border-t-brand-ink" aria-hidden="true"></span>
                                                {{ __('Loading…') }}
                                            </span>
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="{{ $deleteCall }}"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $deleteCall }}"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-white px-2 py-0.5 text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <span wire:loading.remove wire:target="{{ $deleteCall }}" class="inline-flex items-center gap-1">
                                                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Delete') }}
                                            </span>
                                            <span wire:loading wire:target="{{ $deleteCall }}" class="inline-flex items-center gap-1.5">
                                                <span class="inline-block size-3 shrink-0 animate-spin rounded-full border-2 border-red-300 border-t-red-700" aria-hidden="true"></span>
                                                {{ __('Deleting…') }}
                                            </span>
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- One-off --}}
                <div class="px-3 py-3 sm:px-4">
                    <div class="mb-2 flex flex-wrap items-center gap-x-2 gap-y-1">
                        <x-heroicon-o-bolt class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('One-off command') }}</p>
                        <p class="min-w-0 flex-1 text-[11px] text-brand-moss">
                            @if ($container_scope_id !== '')
                                {{ __('Runs inside the scoped container. Output streams above; nothing is saved unless you add it as a recipe.') }}
                            @else
                                {{ __('Output streams above; save it as a recipe when you want to keep it.') }}
                            @endif
                        </p>
                    </div>
                    <form wire:submit="runAdhocCommand" class="space-y-2">
                        <textarea
                            wire:model="adhoc_command"
                            rows="3"
                            @disabled($isRunning)
                            class="w-full rounded-xl border border-brand-ink/15 bg-white font-mono text-xs shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-60"
                            placeholder="{{ $container_scope_id !== '' ? 'php artisan migrate --force' : 'uname -a' }}"
                        ></textarea>
                        <div class="flex flex-wrap items-center gap-3">
                            <x-primary-button type="submit" class="!px-3 !py-1.5 !text-xs" :disabled="$isRunning">
                                {{ $isRunning ? __('Running…') : __('Run command') }}
                            </x-primary-button>
                            <a href="{{ route('scripts.index') }}" wire:navigate class="text-[11px] font-medium text-brand-moss underline-offset-2 hover:text-brand-ink hover:underline">
                                {{ __('Organization scripts') }}
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            @if ($showEditor)
                <div class="dply-card overflow-hidden p-0">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                            <x-heroicon-o-pencil-square class="h-4 w-4 text-brand-forest" aria-hidden="true" />
                            {{ $editing_recipe_id ? __('Edit saved command') : __('New saved command') }}
                        </h3>
                        <p class="min-w-0 flex-1 text-[11px] text-brand-moss">
                            {{ __('Store the command exactly as it should run on this server.') }}
                        </p>
                        <button type="button" wire:click="cancelEditingRecipe" class="shrink-0 text-[11px] font-medium text-brand-moss hover:text-brand-ink">
                            {{ __('Close') }}
                        </button>
                    </div>

                    <div class="px-3 py-3 sm:px-4">
                        @if (! $editing_recipe_id && ! empty($starterTemplates))
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                                <p class="shrink-0 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Start from a template') }}</p>
                                @foreach ($starterTemplates as $template)
                                    <button
                                        type="button"
                                        wire:click="applyStarterTemplate('{{ $template['key'] }}')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2 py-0.5 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        title="{{ $template['description'] }}"
                                    >
                                        {{ $template['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <form wire:submit="addRecipe" @class(['space-y-2.5', 'mt-3' => ! $editing_recipe_id && ! empty($starterTemplates)])>
                            <x-text-input wire:model="new_recipe_name" placeholder="{{ __('Saved command name') }}" />
                            <textarea wire:model="new_recipe_script" rows="12" class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs shadow-sm" placeholder="#!/bin/bash&#10;set -euo pipefail&#10;…"></textarea>
                            <div class="flex flex-wrap gap-2">
                                <x-primary-button type="submit" class="!px-3 !py-1.5 !text-xs">
                                    {{ $editing_recipe_id ? __('Save changes') : __('Add saved command') }}
                                </x-primary-button>
                                <button type="button" wire:click="cancelEditingRecipe" class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    {{ __('Cancel') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    @else
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <x-icon-badge tone="amber">
                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before you can use this section.') }}</p>
                </div>
            </div>
        </section>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])

        @php
            $activeItems = $libraryTab === 'organization' ? $orgScriptItems : $marketplaceItems;
            $tabBtnBase = 'rounded-lg px-3 py-1.5 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-brand-sage/30';
        @endphp
        <x-modal name="browse-library-modal" maxWidth="5xl" :show="$browseLibraryOpen">
            <div class="flex flex-col" style="max-height: min(85vh, 720px);">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Browse the library') }}</h2>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ __('Pick a marketplace preset or an organization script and save it onto this server.') }}
                        </p>
                    </div>
                    <button type="button" wire:click="closeLibrary" class="rounded-lg p-1 text-brand-moss hover:bg-white hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 5l10 10"/>
                            <path d="M15 5l-10 10"/>
                        </svg>
                    </button>
                </header>

                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-white px-6 py-3">
                    <div class="flex gap-1.5">
                        <button
                            type="button"
                            wire:click="setLibraryTab('marketplace')"
                            @class([
                                $tabBtnBase,
                                'bg-brand-ink text-white' => $libraryTab === 'marketplace',
                                'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $libraryTab !== 'marketplace',
                            ])
                        >
                            {{ __('Marketplace') }}
                            <span class="ml-1 text-xs opacity-80">{{ $libraryTotals['marketplace'] }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="setLibraryTab('organization')"
                            @class([
                                $tabBtnBase,
                                'bg-brand-ink text-white' => $libraryTab === 'organization',
                                'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $libraryTab !== 'organization',
                            ])
                        >
                            {{ __('Organization scripts') }}
                            <span class="ml-1 text-xs opacity-80">{{ $libraryTotals['organization'] }}</span>
                        </button>
                    </div>
                    <div class="ml-auto flex items-center gap-2">
                        <label class="sr-only" for="library-search">{{ __('Search') }}</label>
                        <input
                            id="library-search"
                            type="search"
                            wire:model.live.debounce.250ms="librarySearch"
                            placeholder="{{ __('Search by name or content…') }}"
                            class="w-56 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        />
                    </div>
                </div>

                @if ($libraryTab === 'marketplace' && count($libraryAvailableTags) > 0)
                    <div class="flex flex-wrap items-center gap-1.5 border-b border-brand-ink/10 bg-white px-6 py-2.5">
                        <span class="mr-1 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Tags') }}</span>
                        <button
                            type="button"
                            wire:click="setLibraryTagFilter('')"
                            @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-medium transition focus:outline-none focus:ring-2 focus:ring-brand-sage/30',
                                'bg-brand-ink text-white' => $libraryTagFilter === '',
                                'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $libraryTagFilter !== '',
                            ])
                        >
                            {{ __('All') }}
                            <span class="ml-1 text-[11px] opacity-75">{{ $libraryTotals['marketplace'] }}</span>
                        </button>
                        @foreach ($libraryAvailableTags as $tag)
                            <button
                                type="button"
                                wire:click="setLibraryTagFilter('{{ $tag['name'] }}')"
                                @class([
                                    'rounded-full px-2.5 py-0.5 text-xs font-medium transition focus:outline-none focus:ring-2 focus:ring-brand-sage/30',
                                    'bg-brand-sage/15 border border-brand-sage/40 text-brand-sage' => $libraryTagFilter === $tag['name'],
                                    'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $libraryTagFilter !== $tag['name'],
                                ])
                            >
                                {{ $tag['name'] }}
                                <span class="ml-1 text-[11px] opacity-75">{{ $tag['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <div class="grid min-h-0 flex-1 grid-cols-1 md:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]">
                    <div class="min-h-0 overflow-y-auto border-b border-brand-ink/10 bg-brand-sand/10 md:border-b-0 md:border-r" style="max-height: 480px;">
                        @if (count($activeItems) === 0)
                            <div class="px-5 py-6 text-sm text-brand-moss">
                                @if ($libraryTab === 'organization' && $libraryTotals['organization'] === 0)
                                    {{ __('No organization scripts yet. Create one in Scripts and it will show up here.') }}
                                @else
                                    {{ __('Nothing matches your search.') }}
                                @endif
                            </div>
                        @else
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($activeItems as $item)
                                    @php $isSelected = $libraryPreviewId === $item['id']; @endphp
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="previewLibraryItem('{{ $item['id'] }}')"
                                            @class([
                                                'block w-full text-left px-4 py-3 transition',
                                                'bg-white shadow-inner' => $isSelected,
                                                'hover:bg-white/70' => ! $isSelected,
                                            ])
                                        >
                                            <p class="truncate text-sm font-medium text-brand-ink">{{ $item['name'] }}</p>
                                            <p class="mt-0.5 truncate text-xs text-brand-moss">{{ $item['summary'] ?: __('(no summary)') }}</p>
                                            @if ($item['run_as_user'])
                                                <span class="mt-1 inline-flex items-center rounded-full bg-brand-ink/5 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-brand-mist">
                                                    {{ __('runs as :user', ['user' => $item['run_as_user']]) }}
                                                </span>
                                            @endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="min-h-0 overflow-y-auto bg-white" style="max-height: 480px;">
                        @if ($libraryPreview)
                            <div class="flex flex-col gap-4 px-6 py-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="text-base font-semibold text-brand-ink">{{ $libraryPreview['name'] }}</h3>
                                        <p class="mt-1 text-xs text-brand-moss">{{ $libraryPreview['summary'] ?: __('(no summary)') }}</p>
                                        <div class="mt-2 flex flex-wrap gap-2 text-[11px] text-brand-mist">
                                            <span class="rounded-full bg-brand-sand/40 px-2 py-0.5">
                                                {{ $libraryTab === 'organization' ? __('Organization script') : __('Marketplace preset') }}
                                            </span>
                                            @if ($libraryPreview['run_as_user'])
                                                <span class="rounded-full bg-brand-sand/40 px-2 py-0.5">
                                                    {{ __('runs as :user', ['user' => $libraryPreview['run_as_user']]) }}
                                                </span>
                                            @endif
                                            @foreach ($libraryPreview['tags'] ?? [] as $tagName)
                                                <button type="button" wire:click="setLibraryTagFilter('{{ $tagName }}')" class="rounded-full border border-brand-ink/10 bg-white px-2 py-0.5 text-brand-moss hover:border-brand-sage/40 hover:text-brand-sage">
                                                    #{{ $tagName }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 gap-2">
                                        @if ($libraryTab === 'organization')
                                            <button type="button" wire:click="saveOrganizationScriptToServer('{{ $libraryPreview['id'] }}')" class="inline-flex items-center gap-1.5 rounded-xl bg-brand-ink px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90">
                                                {{ __('Save to this server') }}
                                            </button>
                                        @else
                                            <button type="button" wire:click="saveMarketplacePresetToServer('{{ $libraryPreview['id'] }}')" class="inline-flex items-center gap-1.5 rounded-xl bg-brand-ink px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90">
                                                {{ __('Save to this server') }}
                                            </button>
                                        @endif
                                    </div>
                                </div>
                                <pre class="max-h-72 overflow-auto whitespace-pre rounded-lg border border-brand-ink/10 bg-brand-sand/15 p-3 font-mono text-xs leading-relaxed text-brand-ink"
>{{ $libraryPreview['content'] }}</pre>
                                <p class="text-xs text-brand-mist">
                                    {{ __('Saving creates a copy on this server only. Edit it any time from the saved-commands list.') }}
                                </p>
                            </div>
                        @else
                            <div class="flex h-full min-h-[14rem] flex-col items-center justify-center px-6 py-10 text-center text-sm text-brand-moss">
                                <p class="text-brand-ink font-medium">{{ __('Pick something on the left') }}</p>
                                <p class="mt-1 text-xs">
                                    {{ __('Click a row to preview the script. You can save the previewed script to this server with one click.') }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>

                <footer class="flex items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-3 text-xs text-brand-moss">
                    <span>
                        {{ $libraryTab === 'organization'
                            ? __(':count organization script(s) — manage in Scripts.', ['count' => count($orgScriptItems)])
                            : __(':count of :total marketplace presets shown.', ['count' => count($marketplaceItems), 'total' => $libraryTotals['marketplace']]) }}
                    </span>
                    <div class="flex gap-3 font-medium">
                        @if ($libraryTab === 'organization')
                            <a href="{{ route('scripts.index') }}" wire:navigate class="text-brand-ink hover:text-brand-sage">{{ __('Manage scripts →') }}</a>
                        @else
                            <a href="{{ route('scripts.marketplace') }}" wire:navigate class="text-brand-ink hover:text-brand-sage">{{ __('Open marketplace page →') }}</a>
                        @endif
                        <button type="button" wire:click="closeLibrary" class="text-brand-ink hover:text-brand-sage">{{ __('Done') }}</button>
                    </div>
                </footer>
            </div>
        </x-modal>
    </x-slot>
</x-server-workspace-layout>
