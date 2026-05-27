@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="run"
    :title="__('Run')"
    :description="__('Run server-level commands. Saved commands, ad-hoc shell, and library presets all in one place. Site deploys live on each site’s page.')"
>
    @include('livewire.servers.partials.workspace-flashes', ['command_output' => $command_output ?? null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer tone="warn">
        <p>{{ __('Run server-level shell commands over SSH from the workspace: ad-hoc one-offs, saved presets, and library snippets (Laravel artisan, php-fpm restart, etc.). Output streams back into the page.') }}</p>
        <p>{{ __('This is full root-equivalent shell access via the dply SSH key. Treat it like a terminal: command goes in, output comes back, no row-level safety net. Saved commands persist on this server only.') }}</p>
        <p>{{ __('Site deploys are NOT run from here — each site\'s page has its own deploy button so deploys can run with site-scoped context. The banner below points at the right surfaces.') }}</p>
    </x-explainer>

    {{-- Top-of-page banner clarifying scope. The page used to be
         called "Deploy" and operators kept landing here looking for
         site deploys. The banner explicitly redirects them to the
         right surfaces. --}}
    <div class="rounded-2xl border border-sky-200/70 bg-sky-50/60 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 max-w-3xl">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Server-level commands') }}</p>
                <p class="mt-1 text-sm leading-6 text-brand-moss">
                    {{ __('This page runs commands against the whole server. To deploy a site, open the site’s own page. For coordinated multi-site delivery, use the project delivery page.') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('servers.sites', $server) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/30">
                    {{ __('Open Sites') }}
                </a>
                @if ($server->workspace)
                    @feature('surface.projects')
                        <a href="{{ route('projects.delivery', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/30">
                            {{ __('Open Project delivery') }}
                        </a>
                    @endfeature
                @endif
            </div>
        </div>
    </div>

    @if ($opsReady)
        <div class="space-y-6">
            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Library on this server') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __(':count saved · pulled from marketplace presets, organization scripts, or written here.', ['count' => $server->recipes->count()]) }}
                        </p>
                    </div>
                    {{-- flex-nowrap + shrink-0 keeps Browse + Write your own
                         on a single row even when the heading column is wide.
                         The previous flex-wrap let them stack vertically when
                         the right column was narrower than the two buttons'
                         combined width. --}}
                    <div class="flex flex-row flex-nowrap items-center gap-2 shrink-0 sm:justify-end">
                        <button
                            type="button"
                            wire:click="openLibrary"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="3" width="14" height="14" rx="2"/>
                                <path d="M3 8h14"/>
                                <path d="M8 3v14"/>
                            </svg>
                            {{ __('Browse library') }}
                            <span class="rounded-full bg-white/15 px-1.5 py-0.5 text-[10px] font-medium">
                                {{ $libraryTotals['marketplace'] + $libraryTotals['organization'] }}
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="startNewRecipe"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M10 4v12"/>
                                <path d="M4 10h12"/>
                            </svg>
                            {{ __('Write your own') }}
                        </button>
                    </div>
                </div>

                @if ($server->recipes->isEmpty())
                    <div class="mt-6 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-5 py-8 text-center text-sm text-brand-moss">
                        <p class="font-medium text-brand-ink">{{ __('No saved commands yet.') }}</p>
                        <p class="mt-1">
                            {{ __('Open the library to import a marketplace preset or an organization script — or write your own from scratch.') }}
                        </p>
                    </div>
                @else
                    <ul class="mt-6 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10">
                        @foreach ($server->recipes as $rec)
                            <li class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-brand-ink">{{ $rec->name }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">
                                        {{ __('Updated :when', ['when' => $rec->updated_at?->diffForHumans() ?? '—']) }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2 text-xs font-medium">
                                    {{-- Per-row spinners scoped via exact wire:target call signature so
                                         clicking Run on recipe A doesn't dim Run on recipes B + C. --}}
                                    @php
                                        $deleteCall = "openConfirmActionModal('deleteRecipe', ['".$rec->id."'], '".addslashes(__('Delete saved command'))."', '".addslashes(__('Delete saved command?'))."', '".addslashes(__('Delete'))."', true)";
                                    @endphp

                                    <button
                                        type="button"
                                        wire:click="runRecipe('{{ $rec->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="runRecipe('{{ $rec->id }}')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-sage/40 bg-brand-sage/10 px-2.5 py-1 text-brand-sage hover:bg-brand-sage/20 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="runRecipe('{{ $rec->id }}')" class="inline-flex items-center gap-1">
                                            <x-heroicon-o-bolt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
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
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="editRecipe('{{ $rec->id }}')" class="inline-flex items-center gap-1">
                                            <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
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
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-white px-2.5 py-1 text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="{{ $deleteCall }}" class="inline-flex items-center gap-1">
                                            <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
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

            @if ($showEditor)
                <div class="{{ $card }} p-6 sm:p-8">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">
                                {{ $editing_recipe_id ? __('Edit saved command') : __('New saved command') }}
                            </h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('Store the command exactly as it should run on this server. Recipes are listed above and can be run, edited, or deleted any time.') }}
                            </p>
                        </div>
                        <button type="button" wire:click="cancelEditingRecipe" class="text-sm font-medium text-brand-moss hover:text-brand-ink">
                            {{ __('Close') }}
                        </button>
                    </div>

                    @if (! $editing_recipe_id && ! empty($starterTemplates))
                        {{-- Starter templates: pre-fill the form from the
                             small set of presets that the deleted /deploy
                             page used to surface as quick-fill buttons.
                             Only shown when CREATING a new recipe — editing
                             an existing one shouldn't offer a "wipe and
                             repopulate from template" footgun. --}}
                        <div class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Start from a template') }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($starterTemplates as $template)
                                    <button
                                        type="button"
                                        wire:click="applyStarterTemplate('{{ $template['key'] }}')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        title="{{ $template['description'] }}"
                                    >
                                        {{ $template['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <form wire:submit="addRecipe" class="mt-6 space-y-4">
                        <x-text-input wire:model="new_recipe_name" placeholder="{{ __('Saved command name') }}" />
                        <textarea wire:model="new_recipe_script" rows="14" class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs shadow-sm" placeholder="#!/bin/bash&#10;set -euo pipefail&#10;…"></textarea>
                        <div class="flex flex-wrap gap-3">
                            <x-primary-button type="submit" class="!py-2">
                                {{ $editing_recipe_id ? __('Save changes') : __('Add saved command') }}
                            </x-primary-button>
                            <button type="button" wire:click="cancelEditingRecipe" class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                {{ __('Cancel') }}
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Ad-hoc one-off command runner. Absorbed from the deleted
                 /deploy page so /run owns all command execution. Output
                 streams to the live SSH panel via the StreamsRemoteSshLivewire
                 trait — same plumbing the recipe runner uses. --}}
            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Run a one-off command') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __('Type a shell command and run it now. Output streams below; nothing is saved. Save it as a recipe above when you want to keep it around.') }}
                        </p>
                    </div>
                </div>
                <form wire:submit="runAdhocCommand" class="mt-5 space-y-3">
                    <textarea wire:model="adhoc_command" rows="4" class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs shadow-sm" placeholder="uname -a"></textarea>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-primary-button type="submit" class="!py-2">
                            {{ __('Run command') }}
                        </x-primary-button>
                        @if ($command_error)
                            <p class="text-xs text-red-700">{{ $command_error }}</p>
                        @endif
                    </div>
                </form>
            </div>

            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 px-5 py-4 text-sm text-brand-moss">
                <p class="font-medium text-brand-ink">{{ __('Where else commands live') }}</p>
                <p class="mt-1 leading-relaxed">
                    {{ __('Saved commands stay on this server only. Use Scripts for organization-wide reusable automation.') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-3 text-sm font-medium">
                    <a href="{{ route('scripts.index') }}" wire:navigate class="text-brand-ink hover:text-brand-sage">{{ __('Open scripts') }}</a>
                </div>
            </div>
        </div>
    @else
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-900 ring-amber-200">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before you can use this section.') }}</p>
                    </div>
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
