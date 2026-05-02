@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="recipes"
    :title="__('Saved commands')"
    :description="__('Server-local runbooks, diagnostics, and one-off operational scripts. Browse the library to import a starter, or write your own — everything lands here on this server.')"
>
    @include('livewire.servers.partials.workspace-flashes', ['command_output' => $command_output ?? null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady)
        <div class="space-y-6">
            @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => 18])

            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Library on this server') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __(':count saved · pulled from marketplace presets, organization scripts, or written here.', ['count' => $server->recipes->count()]) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 sm:justify-end">
                        <button
                            type="button"
                            wire:click="openLibrary"
                            class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="3" width="14" height="14" rx="2"/>
                                <path d="M3 8h14"/>
                                <path d="M8 3v14"/>
                            </svg>
                            {{ __('Browse library') }}
                            <span class="rounded-full bg-white/15 px-2 py-0.5 text-[11px] font-medium">
                                {{ $libraryTotals['marketplace'] + $libraryTotals['organization'] }}
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="startNewRecipe"
                            class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
                                    <button type="button" wire:click="runRecipe('{{ $rec->id }}')" class="rounded-lg border border-brand-sage/40 bg-brand-sage/10 px-2.5 py-1 text-brand-sage hover:bg-brand-sage/20">{{ __('Run') }}</button>
                                    <button type="button" wire:click="editRecipe('{{ $rec->id }}')" class="rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-brand-ink hover:bg-brand-sand/40">{{ __('Edit') }}</button>
                                    <button type="button" wire:click="useRecipeAsDeployCommand('{{ $rec->id }}')" class="rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-brand-ink hover:bg-brand-sand/40">{{ __('Use as deploy') }}</button>
                                    <button type="button" wire:click="openConfirmActionModal('deleteRecipe', ['{{ $rec->id }}'], @js(__('Delete saved command')), @js(__('Delete saved command?')), @js(__('Delete')), true)" class="rounded-lg border border-red-200 bg-white px-2.5 py-1 text-red-600 hover:bg-red-50">{{ __('Delete') }}</button>
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
                            <h2 class="text-lg font-semibold text-brand-ink">
                                {{ $editing_recipe_id ? __('Edit saved command') : __('New saved command') }}
                            </h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('Store the command exactly as it should run on this server. Promote into Deploy explicitly when it becomes part of the release flow.') }}
                            </p>
                        </div>
                        <button type="button" wire:click="cancelEditingRecipe" class="text-sm font-medium text-brand-moss hover:text-brand-ink">
                            {{ __('Close') }}
                        </button>
                    </div>

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

            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 px-5 py-4 text-sm text-brand-moss">
                <p class="font-medium text-brand-ink">{{ __('Where else commands live') }}</p>
                <p class="mt-1 leading-relaxed">
                    {{ __('Saved commands stay on this server only. Use Deploy for release commands and Scripts for organization-wide reusable automation.') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-3 text-sm font-medium">
                    <a href="{{ route('servers.deploy', $server) }}" wire:navigate class="text-brand-ink hover:text-brand-sage">{{ __('Open deploy') }}</a>
                    <a href="{{ route('scripts.index') }}" wire:navigate class="text-brand-ink hover:text-brand-sage">{{ __('Open scripts') }}</a>
                </div>
            </div>
        </div>
    @else
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before you can use this section.') }}
        </div>
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
        <x-modal name="browse-library-modal" maxWidth="2xl" :show="$browseLibraryOpen">
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
