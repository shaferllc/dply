{{-- Edit + create dialogs for a notification channel, shared by every page
     body variant so a redesign of the list cannot lose the forms. --}}
{{-- Edit lives in a modal, not an inline row: the form is as tall as the create
     one, and expanding it inside the table shoved every other channel offscreen. --}}
<x-modal
    name="settings-edit-channel-modal"
    {{-- Bound to state, not just the open-modal event: the modal is teleported,
         so the morph that follows startEdit re-inits its Alpine data and an
         event-only open would snap straight back shut. --}}
    :show="$editing_id !== null"
    maxWidth="2xl"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
    focusable
>
        <form wire:submit="saveEdit" class="flex min-h-0 flex-1 flex-col">
            <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-pencil-square class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Edit') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Edit notification channel') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-brand-moss">
                        {{ __('Change where this channel delivers. Existing event subscriptions stay attached.') }}
                    </p>
                </div>
            </div>

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="edit_type" :value="__('Type')" />
                        <x-select id="edit_type" wire:model.live="edit_type">
                            @foreach ($typesForEdit as $t)
                                <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                            @endforeach
                        </x-select>
                        @error('edit_type')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <x-input-label for="edit_label" :value="__('Label')" />
                        <x-text-input id="edit_label" type="text" wire:model="edit_label" required />
                        @error('edit_label')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'edit_', 'type' => $edit_type])
            </div>

            <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelEdit">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdit"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="saveEdit" class="inline-flex items-center gap-2">
                        <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Save changes') }}
                    </span>
                    <span wire:loading wire:target="saveEdit" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Saving…') }}
                    </span>
                </button>
            </div>
        </form>
</x-modal>

@if ($canManage && count($types) > 0)
    <x-modal
        name="settings-create-channel-modal"
        :show="false"
        maxWidth="2xl"
        overlayClass="bg-brand-ink/30"
        panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
        focusable
    >
        <form wire:submit="createChannel" class="flex min-h-0 flex-1 flex-col">
            <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-plus-circle class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Create notification channel') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-brand-moss">
                        {{ __('Connect chat, email, Pushover, webhooks, or mobile tokens. Credentials are stored encrypted.') }}
                    </p>
                </div>
            </div>

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="new_type_modal" :value="__('Type')" />
                        <x-select id="new_type_modal" wire:model.live="new_type">
                            @foreach ($types as $t)
                                <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                            @endforeach
                        </x-select>
                        @error('new_type')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <x-input-label for="new_label_modal" :value="__('Label')" />
                        <x-text-input id="new_label_modal" type="text" wire:model="new_label" placeholder="{{ __('e.g. #alerts') }}" required />
                        @error('new_label')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'new_', 'type' => $new_type])
            </div>

            <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeCreateChannelModal">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="createChannel"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="createChannel" class="inline-flex items-center gap-2">
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Create channel') }}
                    </span>
                    <span wire:loading wire:target="createChannel" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Creating…') }}
                    </span>
                </button>
            </div>
        </form>
    </x-modal>
@endif
