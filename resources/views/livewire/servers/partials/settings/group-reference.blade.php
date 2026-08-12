@php
    $canEdit = $this->canEditServerSettings;
    $counts = $this->serverNoteCounts;
    $tagCloud = $this->serverNoteTags;
    $tagSuggestions = array_column($tagCloud, 'tag');
    $isFiltered = $this->serverNotesAreFiltered();

    $filterTab = 'inline-flex h-7 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40';
@endphp

<section id="settings-group-reference" aria-labelledby="settings-group-reference-title">
    <div id="settings-notes" class="{{ $card }} scroll-mt-24">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-document-text"
            :title="__('Internal notes')"
            :note="__('Free-form context: runbooks, customer IDs, things the next engineer should know. Tag notes to group them, pin one to surface it on the server overview, archive what has gone stale.')"
            title-id="settings-group-reference-title"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                @if ($counts['all'] > 0)
                    <x-dropdown align="right" width="20rem">
                        <x-slot:trigger>
                            <button
                                type="button"
                                class="inline-flex h-7 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40"
                            >
                                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Export') }}
                            </button>
                        </x-slot:trigger>

                        <x-slot:content>
                            <p class="px-3 pb-1.5 pt-1 text-xs text-brand-moss">
                                {{ $isFiltered ? __('Exports the :count matching :notes.', ['count' => $this->serverNotes->count(), 'notes' => trans_choice('note|notes', $this->serverNotes->count())]) : __('Exports all :count notes.', ['count' => $counts['all']]) }}
                            </p>

                            <x-dropdown-link href="#" wire:click.prevent="exportServerNotesMarkdown" :description="__('One readable document — for a handover or a wiki.')">
                                <x-slot:icon><x-heroicon-o-document-text aria-hidden="true" /></x-slot:icon>
                                {{ __('Download Markdown') }}
                            </x-dropdown-link>

                            <x-dropdown-link href="#" wire:click.prevent="exportServerNotesJson" :description="__('Tags, archive state, attribution and comments.')">
                                <x-slot:icon><x-heroicon-o-code-bracket aria-hidden="true" /></x-slot:icon>
                                {{ __('Download JSON') }}
                            </x-dropdown-link>
                        </x-slot:content>
                    </x-dropdown>
                @endif
            </x-slot:actions>
        </x-workspace-panel-head>

        {{-- Compose a new note --}}
        @if ($canEdit)
            <form wire:submit="addServerNote" class="space-y-3 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                <x-markdown-editor
                    property="noteDraft"
                    :value="$noteDraft"
                    :placeholder="__('Write a note… Markdown supported (e.g. **bold**, lists, `code`).')"
                    :max-length="10000"
                    :rows="5"
                />
                <x-input-error :messages="$errors->get('noteDraft')" />

                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div class="min-w-0 flex-1">
                        <x-tag-input
                            property="noteDraftTags"
                            :value="$noteDraftTags"
                            :suggestions="$tagSuggestions"
                            :label="__('Tags for the new note')"
                            :placeholder="__('Add a tag… (e.g. runbook, billing)')"
                        />
                        <x-input-error :messages="$errors->get('noteDraftTags')" class="mt-1" />
                    </div>

                    <x-primary-button
                        type="submit"
                        size="xs"
                        class="h-9 shrink-0 sm:mt-0"
                        wire:loading.attr="disabled"
                        wire:target="addServerNote"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Add note') }}
                    </x-primary-button>
                </div>
            </form>
        @endif

        {{-- Filter strip: archive state, search, tag chips --}}
        @if ($counts['all'] > 0)
            <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-3 sm:px-6">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-0.5 rounded-lg bg-white p-0.5 shadow-sm" role="group" aria-label="{{ __('Filter notes by state') }}">
                        @foreach ([
                            'active' => __('Active'),
                            'archived' => __('Archived'),
                            'all' => __('All'),
                        ] as $key => $label)
                            <button
                                type="button"
                                wire:click="setServerNoteFilter('{{ $key }}')"
                                @class([
                                    $filterTab,
                                    'bg-brand-ink text-brand-cream' => $noteFilter === $key,
                                    'text-brand-moss hover:text-brand-ink' => $noteFilter !== $key,
                                ])
                                aria-pressed="{{ $noteFilter === $key ? 'true' : 'false' }}"
                            >
                                {{ $label }}
                                <span @class([
                                    'rounded-full px-1.5 text-xs font-semibold tabular-nums',
                                    'bg-brand-cream/20 text-brand-cream' => $noteFilter === $key,
                                    'bg-brand-ink/10 text-brand-moss' => $noteFilter !== $key,
                                ])>{{ $counts[$key] }}</span>
                            </button>
                        @endforeach
                    </div>

                    <label class="relative ml-auto w-full sm:w-64">
                        <span class="sr-only">{{ __('Search notes') }}</span>
                        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-moss" aria-hidden="true" />
                        <input
                            type="search"
                            wire:model.live.debounce.300ms="noteSearch"
                            placeholder="{{ __('Search notes and tags…') }}"
                            class="h-8 w-full rounded-lg border border-brand-ink/15 bg-white pl-8 pr-3 text-sm text-brand-ink placeholder:text-brand-moss/70 focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/20"
                        >
                    </label>

                    @if ($isFiltered)
                        <button
                            type="button"
                            wire:click="clearServerNoteFilters"
                            class="inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-semibold text-brand-sage transition hover:text-brand-forest"
                        >
                            <x-heroicon-o-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Clear') }}
                        </button>
                    @endif
                </div>

                @if ($tagCloud !== [])
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs font-medium text-brand-moss">{{ __('Tags') }}</span>
                        @foreach ($tagCloud as $entry)
                            <button
                                type="button"
                                wire:click="filterServerNotesByTag('{{ $entry['tag'] }}')"
                                @class([
                                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium transition',
                                    'bg-brand-ink text-brand-cream' => $noteTagFilter === $entry['tag'],
                                    'bg-white text-brand-ink ring-1 ring-brand-ink/10 hover:bg-brand-sand/50' => $noteTagFilter !== $entry['tag'],
                                ])
                                aria-pressed="{{ $noteTagFilter === $entry['tag'] ? 'true' : 'false' }}"
                            >
                                {{ $entry['tag'] }}
                                <span class="tabular-nums opacity-60">{{ $entry['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- The notebook --}}
        <div class="space-y-4 px-5 py-4 sm:px-6">
            @forelse ($this->serverNotes as $note)
                @include('livewire.servers.partials.settings.notes._note-card', [
                    'note' => $note,
                    'canEdit' => $canEdit,
                    'tagSuggestions' => $tagSuggestions,
                ])
            @empty
                <div class="rounded-xl border border-dashed border-brand-ink/15 px-4 py-8 text-center">
                    @if ($isFiltered)
                        <p class="text-sm text-brand-moss">{{ __('No notes match these filters.') }}</p>
                        <button
                            type="button"
                            wire:click="clearServerNoteFilters"
                            class="mt-2 text-xs font-semibold text-brand-sage transition hover:text-brand-forest"
                        >{{ __('Clear filters') }}</button>
                    @else
                        <p class="text-sm text-brand-moss">{{ __('No notes yet.') }}</p>
                        @if ($canEdit)
                            <p class="mt-1 text-xs text-brand-moss/80">{{ __('Add runbooks, customer IDs, or anything the next engineer should know.') }}</p>
                        @endif
                    @endif
                </div>
            @endforelse
        </div>
    </div>
</section>
