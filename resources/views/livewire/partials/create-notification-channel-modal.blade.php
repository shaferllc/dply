{{-- Reusable inline "Create notification channel" modal. Paired with the
     CreatesNotificationChannelInline trait. Any Livewire host that wires the
     trait + renders this partial inside its `modals` slot (or anywhere in
     scope) gets the same Settings → Notifications create form without sending
     the operator off the page.

     Reuses livewire.settings.partials.notification-channel-fields so the
     kind-specific input groups stay in sync with the central Settings page —
     add a new channel kind there and this modal picks it up automatically. --}}
@php
    $allowedChannelTypes = \App\Models\NotificationChannel::typesForUi();
@endphp

@if (($createChannelModalOpen ?? false) && $allowedChannelTypes !== [])
    <x-modal
        name="create-notification-channel-modal"
        maxWidth="xl"
        overlayClass="bg-brand-ink/40"
        panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,820px)] flex-col"
    >
        <div class="shrink-0 border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Notifications') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Create notification channel') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Add a destination for alerts — chat, email, webhook, mobile. Credentials are stored encrypted.') }}</p>
        </div>

        <form wire:submit="submitCreateChannelInline" class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="new_type" :value="__('Type')" />
                    <x-select id="new_type" wire:model.live="new_type">
                        @foreach ($allowedChannelTypes as $t)
                            <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                        @endforeach
                    </x-select>
                    @error('new_type')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <x-input-label for="new_label" :value="__('Label')" />
                    <x-text-input
                        id="new_label"
                        type="text"
                        wire:model="new_label"
                        placeholder="{{ __('e.g. #alerts, oncall@example.com') }}"
                        required
                    />
                    @error('new_label')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'new_', 'type' => $new_type])

            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <button
                    type="button"
                    wire:click="cancelCreateChannelModal"
                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-cream"
                >
                    {{ __('Cancel') }}
                </button>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="submitCreateChannelInline">
                    <span wire:loading.remove wire:target="submitCreateChannelInline">{{ __('Create channel') }}</span>
                    <span wire:loading wire:target="submitCreateChannelInline">{{ __('Creating…') }}</span>
                </x-primary-button>
            </div>
        </form>
    </x-modal>
@endif
