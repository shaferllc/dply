@php
    /** @var \App\Models\ServerNote $note */
    $isEditing = $this->editingNoteId === $note->id;
    $isArchived = $note->isArchived();
    $commentCount = (int) ($note->comments_count ?? 0);
    $commentsOpen = $this->noteCommentsAreOpen($note->id);

    $rowAction = 'inline-flex items-center gap-1 text-xs font-medium text-brand-sage transition hover:text-brand-forest';
@endphp

<article
    wire:key="note-{{ $note->id }}"
    @class([
        'rounded-xl border transition',
        'border-brand-sage/40 bg-brand-sage/5' => $note->pinned && ! $isArchived,
        'border-brand-ink/10 bg-brand-sand/10' => $isArchived,
        'border-brand-ink/10 bg-white' => ! $note->pinned && ! $isArchived,
    ])
>
    <div class="px-4 py-4 sm:px-5">
        @if ($isEditing)
            <form wire:submit="updateServerNote" class="space-y-3">
                <x-markdown-editor
                    property="editingNoteBody"
                    :value="$editingNoteBody"
                    :max-length="10000"
                    :rows="8"
                />
                <x-input-error :messages="$errors->get('editingNoteBody')" />

                <x-tag-input
                    property="editingNoteTags"
                    :value="$editingNoteTags"
                    :suggestions="$tagSuggestions"
                    :label="__('Tags for this note')"
                />
                <x-input-error :messages="$errors->get('editingNoteTags')" />

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-brand-ink/10 pt-4">
                    <x-secondary-button type="button" size="xs" wire:click="cancelEditingServerNote">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="submit" size="xs" wire:loading.attr="disabled" wire:target="updateServerNote">
                        {{ __('Save changes') }}
                    </x-primary-button>
                </div>
            </form>
        @else
            {{-- Status badges --}}
            @if ($note->pinned || $isArchived)
                <div class="mb-2 flex flex-wrap items-center gap-1.5">
                    @if ($note->pinned)
                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-brand-forest">
                            <x-heroicon-s-bookmark class="h-3 w-3" aria-hidden="true" />
                            {{ __('Pinned') }}
                        </span>
                    @endif
                    @if ($isArchived)
                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-ink/10 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                            <x-heroicon-o-archive-box class="h-3 w-3" aria-hidden="true" />
                            {{ __('Archived') }}
                        </span>
                    @endif
                </div>
            @endif

            <x-markdown :content="$note->body" :class="$isArchived ? 'opacity-75' : ''" />

            {{-- Tags --}}
            @if ($note->tagList() !== [])
                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    @foreach ($note->tagList() as $tag)
                        <button
                            type="button"
                            wire:click="filterServerNotesByTag('{{ $tag }}')"
                            @class([
                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium transition',
                                'bg-brand-ink text-brand-cream' => $noteTagFilter === $tag,
                                'bg-brand-sand/60 text-brand-ink hover:bg-brand-sand' => $noteTagFilter !== $tag,
                            ])
                            title="{{ __('Filter by :tag', ['tag' => $tag]) }}"
                        >
                            <x-heroicon-o-tag class="h-3 w-3" aria-hidden="true" />
                            {{ $tag }}
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Attribution + actions --}}
            <div class="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-brand-ink/5 pt-3">
                <p class="text-xs text-brand-moss">
                    {{ $note->creator ? __('Added by :name', ['name' => $note->creator->name]) : __('Added') }}
                    <span title="{{ $note->created_at?->toDayDateTimeString() }}">{{ $note->created_at?->diffForHumans() }}</span>
                    @if ($note->wasEdited())
                        · {{ $note->editor ? __('edited by :name', ['name' => $note->editor->name]) : __('edited') }}
                        <span title="{{ $note->updated_at?->toDayDateTimeString() }}">{{ $note->updated_at?->diffForHumans() }}</span>
                    @endif
                    @if ($isArchived)
                        · {{ $note->archiver ? __('archived by :name', ['name' => $note->archiver->name]) : __('archived') }}
                        <span title="{{ $note->archived_at?->toDayDateTimeString() }}">{{ $note->archived_at?->diffForHumans() }}</span>
                    @endif
                </p>

                <div class="flex items-center gap-3">
                    <button type="button" wire:click="toggleNoteComments('{{ $note->id }}')" class="{{ $rowAction }}">
                        <x-heroicon-o-chat-bubble-left-ellipsis class="h-3.5 w-3.5" aria-hidden="true" />
                        @if ($commentCount > 0)
                            {{ trans_choice(':count comment|:count comments', $commentCount, ['count' => $commentCount]) }}
                        @else
                            {{ __('Comment') }}
                        @endif
                    </button>

                    @if ($canEdit)
                        {{-- The two everyday actions stay in the row; the rest live
                             in the overflow so the card doesn't grow a toolbar. --}}
                        @unless ($isArchived)
                            <button type="button" wire:click="toggleServerNotePin('{{ $note->id }}')" class="{{ $rowAction }}">
                                {{ $note->pinned ? __('Unpin') : __('Pin') }}
                            </button>
                        @endunless

                        <button type="button" wire:click="startEditingServerNote('{{ $note->id }}')" class="{{ $rowAction }}">
                            {{ __('Edit') }}
                        </button>

                        <x-dropdown align="right" width="17rem">
                            <x-slot:trigger>
                                <button
                                    type="button"
                                    class="inline-flex h-6 w-6 items-center justify-center rounded-lg text-brand-moss transition hover:bg-brand-sand/50 hover:text-brand-ink"
                                    aria-label="{{ __('More note actions') }}"
                                >
                                    <x-heroicon-o-ellipsis-horizontal class="h-4 w-4" aria-hidden="true" />
                                </button>
                            </x-slot:trigger>

                            <x-slot:content>
                                <x-dropdown-link href="#" wire:click.prevent="duplicateServerNote('{{ $note->id }}')">
                                    <x-slot:icon><x-heroicon-o-document-duplicate aria-hidden="true" /></x-slot:icon>
                                    {{ __('Duplicate') }}
                                </x-dropdown-link>

                                @if ($isArchived)
                                    <x-dropdown-link href="#" wire:click.prevent="restoreServerNote('{{ $note->id }}')">
                                        <x-slot:icon><x-heroicon-o-arrow-uturn-left aria-hidden="true" /></x-slot:icon>
                                        {{ __('Restore') }}
                                    </x-dropdown-link>
                                @else
                                    <x-dropdown-link href="#" wire:click.prevent="archiveServerNote('{{ $note->id }}')" :description="__('Hides it from the active list; nothing is lost.')">
                                        <x-slot:icon><x-heroicon-o-archive-box-arrow-down aria-hidden="true" /></x-slot:icon>
                                        {{ __('Archive') }}
                                    </x-dropdown-link>
                                @endif

                                {{-- Arm-then-confirm rather than a native confirm()
                                     dialog; the second click within the panel does
                                     the delete. --}}
                                <div x-data="{ armed: false }" class="border-t border-brand-ink/5 pt-1">
                                    <button
                                        type="button"
                                        x-show="! armed"
                                        x-on:click.stop="armed = true"
                                        class="group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start text-sm font-medium text-rose-600 transition hover:bg-rose-50"
                                    >
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-rose-500/10 text-rose-600 ring-1 ring-rose-500/15" aria-hidden="true">
                                            <x-heroicon-o-trash class="h-[1.15rem] w-[1.15rem]" />
                                        </span>
                                        {{ __('Delete') }}
                                    </button>

                                    <button
                                        type="button"
                                        x-show="armed"
                                        x-cloak
                                        wire:click="deleteServerNote('{{ $note->id }}')"
                                        class="group flex w-full items-center gap-3 rounded-xl bg-rose-50 px-3 py-2.5 text-start text-sm font-semibold text-rose-700 transition hover:bg-rose-100"
                                    >
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-rose-500/15 text-rose-700 ring-1 ring-rose-500/25" aria-hidden="true">
                                            <x-heroicon-o-trash class="h-[1.15rem] w-[1.15rem]" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block">{{ __('Delete permanently?') }}</span>
                                            <span class="mt-0.5 block text-xs font-normal leading-snug text-rose-600/80">
                                                {{ $commentCount > 0 ? trans_choice('Removes the note and :count comment.|Removes the note and :count comments.', $commentCount, ['count' => $commentCount]) : __('This cannot be undone.') }}
                                            </span>
                                        </span>
                                    </button>
                                </div>
                            </x-slot:content>
                        </x-dropdown>
                    @endif
                </div>
            </div>
        @endif
    </div>

    @if ($commentsOpen)
        @include('livewire.servers.partials.settings.notes._note-comments', [
            'note' => $note,
            'canEdit' => $canEdit,
        ])
    @endif
</article>
