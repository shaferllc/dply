@php
    /** @var \App\Models\ServerNote $note */
    $comments = $this->commentsForNote($note->id);
@endphp

{{-- Comment thread under a note. Rendered only while the thread is expanded
     (see ManagesServerNoteComments::$openNoteComments), so a busy notebook
     doesn't load every discussion on every render. --}}
<div wire:key="note-comments-{{ $note->id }}" class="rounded-b-xl border-t border-brand-ink/10 bg-brand-sand/20 px-4 py-3 sm:px-5">
    @if ($comments->isEmpty())
        <p class="text-xs text-brand-moss">{{ __('No comments yet.') }}</p>
    @else
        <ul class="space-y-3">
            @foreach ($comments as $comment)
                <li wire:key="comment-{{ $comment->id }}" class="rounded-lg border border-brand-ink/10 bg-white px-3 py-2.5">
                    @if ($this->editingCommentId === $comment->id)
                        <form wire:submit="updateNoteComment" class="space-y-2">
                            <textarea
                                wire:model="editingCommentBody"
                                rows="3"
                                maxlength="2000"
                                class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/20"
                            ></textarea>
                            <x-input-error :messages="$errors->get('editingCommentBody')" />
                            <div class="flex items-center justify-end gap-2">
                                <x-secondary-button type="button" size="xs" wire:click="cancelEditingNoteComment">
                                    {{ __('Cancel') }}
                                </x-secondary-button>
                                <x-primary-button type="submit" size="xs" wire:loading.attr="disabled" wire:target="updateNoteComment">
                                    {{ __('Save') }}
                                </x-primary-button>
                            </div>
                        </form>
                    @else
                        <x-markdown :content="$comment->body" />

                        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                            <p class="text-xs text-brand-moss">
                                {{ $comment->creator?->name ?? __('Unknown') }}
                                <span title="{{ $comment->created_at?->toDayDateTimeString() }}">{{ $comment->created_at?->diffForHumans() }}</span>
                                @if ($comment->wasEdited())
                                    · {{ __('edited') }}
                                @endif
                            </p>

                            @if ($canEdit)
                                <div class="flex items-center gap-3 text-xs font-medium" x-data="{ armed: false }">
                                    <button
                                        type="button"
                                        wire:click="startEditingNoteComment('{{ $comment->id }}')"
                                        class="text-brand-sage transition hover:text-brand-forest"
                                    >{{ __('Edit') }}</button>

                                    {{-- Arm-then-confirm keeps the delete off a
                                         native browser dialog. --}}
                                    <button
                                        type="button"
                                        x-show="! armed"
                                        x-on:click="armed = true"
                                        class="text-rose-600 transition hover:text-rose-700"
                                    >{{ __('Delete') }}</button>
                                    <button
                                        type="button"
                                        x-show="armed"
                                        x-cloak
                                        x-on:click="armed = false"
                                        wire:click="deleteNoteComment('{{ $comment->id }}')"
                                        class="font-semibold text-rose-700 underline decoration-rose-300 underline-offset-2 transition hover:text-rose-800"
                                    >{{ __('Confirm delete') }}</button>
                                </div>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canEdit)
        <form wire:submit="addNoteComment('{{ $note->id }}')" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start">
            <div class="min-w-0 flex-1">
                <textarea
                    wire:model="commentDrafts.{{ $note->id }}"
                    rows="2"
                    maxlength="2000"
                    placeholder="{{ __('Add a comment… Markdown supported.') }}"
                    class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-moss/70 focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/20"
                ></textarea>
                <x-input-error :messages="$errors->get('commentDrafts.'.$note->id)" class="mt-1" />
            </div>

            <x-primary-button
                type="submit"
                size="xs"
                class="h-9 shrink-0"
                wire:loading.attr="disabled"
                wire:target="addNoteComment('{{ $note->id }}')"
            >
                {{ __('Comment') }}
            </x-primary-button>
        </form>
    @endif
</div>
