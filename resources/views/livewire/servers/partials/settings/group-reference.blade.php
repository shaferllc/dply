<section id="settings-group-reference" class="space-y-6" aria-labelledby="settings-group-reference-title">
    <div id="settings-notes" class="{{ $card }} scroll-mt-24">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Notes') }}</p>
                <h3 id="settings-group-reference-title" class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Internal notes') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Free-form context: runbooks, customer IDs, things the next engineer should know. Markdown is supported — pin a note to surface it on the server overview.') }}</p>
            </div>
        </div>

        <div class="space-y-5 px-6 py-6 sm:px-7">
            {{-- Compose a new note --}}
            @if ($this->canEditServerSettings)
                <form wire:submit="addServerNote" class="space-y-3">
                    <textarea
                        wire:model="noteDraft"
                        rows="4"
                        placeholder="{{ __('Write a note… Markdown supported (e.g. **bold**, lists, `code`).') }}"
                        class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    ></textarea>
                    <x-input-error :messages="$errors->get('noteDraft')" />
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs text-brand-moss">{{ __('Supports Markdown. Up to 10,000 characters.') }}</p>
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="addServerNote">{{ __('Add note') }}</x-primary-button>
                    </div>
                </form>

                <div class="border-t border-brand-ink/10"></div>
            @endif

            {{-- Existing notes --}}
            @forelse ($this->serverNotes as $note)
                <article
                    wire:key="note-{{ $note->id }}"
                    @class([
                        'rounded-xl border px-4 py-4 sm:px-5',
                        'border-brand-sage/40 bg-brand-sage/5' => $note->pinned,
                        'border-brand-ink/10 bg-white' => ! $note->pinned,
                    ])
                >
                    @if ($this->editingNoteId === $note->id)
                        <form wire:submit="updateServerNote" class="space-y-3">
                            <textarea
                                wire:model="editingNoteBody"
                                rows="5"
                                class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            ></textarea>
                            <x-input-error :messages="$errors->get('editingNoteBody')" />
                            <div class="flex items-center gap-2">
                                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="updateServerNote">{{ __('Save changes') }}</x-primary-button>
                                <x-secondary-button type="button" wire:click="cancelEditingServerNote">{{ __('Cancel') }}</x-secondary-button>
                            </div>
                        </form>
                    @else
                        @if ($note->pinned)
                            <div class="mb-2 inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-forest">
                                <x-heroicon-s-bookmark class="h-3 w-3" aria-hidden="true" />
                                {{ __('Pinned') }}
                            </div>
                        @endif

                        <x-markdown :content="$note->body" />

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-brand-ink/5 pt-3">
                            <p class="text-xs text-brand-moss">
                                {{ $note->creator ? __('Added by :name', ['name' => $note->creator->name]) : __('Added') }}
                                <span title="{{ $note->created_at?->toDayDateTimeString() }}">{{ $note->created_at?->diffForHumans() }}</span>
                                @if ($note->updated_at && $note->created_at && $note->updated_at->gt($note->created_at->addMinute()))
                                    · {{ $note->editor ? __('edited by :name', ['name' => $note->editor->name]) : __('edited') }}
                                    <span title="{{ $note->updated_at?->toDayDateTimeString() }}">{{ $note->updated_at?->diffForHumans() }}</span>
                                @endif
                            </p>

                            @if ($this->canEditServerSettings)
                                <div class="flex items-center gap-3 text-xs font-medium">
                                    <button type="button" wire:click="toggleServerNotePin('{{ $note->id }}')" class="text-brand-sage transition hover:text-brand-forest">
                                        {{ $note->pinned ? __('Unpin') : __('Pin') }}
                                    </button>
                                    <button type="button" wire:click="startEditingServerNote('{{ $note->id }}')" class="text-brand-sage transition hover:text-brand-forest">{{ __('Edit') }}</button>
                                    <button
                                        type="button"
                                        wire:click="deleteServerNote('{{ $note->id }}')"
                                        wire:confirm="{{ __('Delete this note? This cannot be undone.') }}"
                                        class="text-rose-600 transition hover:text-rose-700"
                                    >{{ __('Delete') }}</button>
                                </div>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-brand-ink/15 px-4 py-8 text-center">
                    <p class="text-sm text-brand-moss">{{ __('No notes yet.') }}</p>
                    @if ($this->canEditServerSettings)
                        <p class="mt-1 text-xs text-brand-moss/80">{{ __('Add runbooks, customer IDs, or anything the next engineer should know.') }}</p>
                    @endif
                </div>
            @endforelse
        </div>
    </div>
</section>
