@props([
    'show' => false,
    'title' => __('Quick add channel'),
    'description' => __('Create a destination here, then it will be selected automatically for assignment.'),
    'types' => [],
    'currentType' => null,
    'canManageOrganizationNotificationChannels' => false,
    'createAction' => 'createQuickNotificationChannel',
    'closeAction' => 'closeQuickNotificationChannelModal',
])

@if ($show)
    <div
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="notification-channel-quick-add-title"
        x-data
        x-on:keydown.escape.window="$wire.{{ $closeAction }}()"
    >
        <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="{{ $closeAction }}"></div>
        <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
            <div class="relative w-full max-w-2xl rounded-2xl border border-brand-ink/10 bg-white shadow-xl" wire:click.stop>
                <div class="border-b border-brand-ink/10 px-6 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 id="notification-channel-quick-add-title" class="text-lg font-semibold text-brand-ink">{{ $title }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ $description }}</p>
                        </div>
                        <button
                            type="button"
                            wire:click="{{ $closeAction }}"
                            class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            {{ __('Close') }}
                        </button>
                    </div>
                </div>

                <div class="px-6 py-5">
                    @if ($types === [])
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/10 px-4 py-4 text-sm text-brand-moss">
                            {{ __('No notification channel types are enabled right now.') }}
                        </div>
                    @else
                        <div class="grid gap-4 lg:grid-cols-3">
                            @if ($canManageOrganizationNotificationChannels)
                                <div>
                                    <x-input-label for="quick_new_owner_scope" :value="__('Owner')" />
                                    <select
                                        id="quick_new_owner_scope"
                                        wire:model.live="quick_new_owner_scope"
                                        class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    >
                                        <option value="organization">{{ __('Organization') }}</option>
                                        <option value="personal">{{ __('Personal') }}</option>
                                    </select>
                                    @error('quick_new_owner_scope')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif
                            <div>
                                <x-input-label for="quick_new_type" :value="__('Type')" />
                                <select
                                    id="quick_new_type"
                                    wire:model.live="quick_new_type"
                                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                >
                                    @foreach ($types as $type)
                                        <option value="{{ $type }}">{{ \App\Models\NotificationChannel::labelForType($type) }}</option>
                                    @endforeach
                                </select>
                                @error('quick_new_type')
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <x-input-label for="quick_new_label" :value="__('Label')" />
                                <input
                                    id="quick_new_label"
                                    type="text"
                                    wire:model="quick_new_label"
                                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    placeholder="{{ __('e.g. Ops alerts') }}"
                                />
                                @error('quick_new_label')
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="mt-4">
                            @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'quick_new_', 'type' => $currentType])
                        </div>
                    @endif
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-brand-ink/10 px-6 py-4 sm:flex-row sm:justify-end sm:gap-3">
                    <button
                        type="button"
                        wire:click="{{ $closeAction }}"
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="{{ $createAction }}"
                        wire:loading.attr="disabled"
                        wire:target="{{ $createAction }}"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-50"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0 opacity-90" />
                        <span wire:loading.remove wire:target="{{ $createAction }}">{{ __('Create channel') }}</span>
                        <span wire:loading wire:target="{{ $createAction }}">{{ __('Creating…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
