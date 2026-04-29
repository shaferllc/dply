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
        x-data="{
            focusables() {
                let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
                return [...$el.querySelectorAll(selector)].filter(el => ! el.hasAttribute('disabled'))
            },
            firstFocusable() { return this.focusables()[0] },
            lastFocusable() { return this.focusables().slice(-1)[0] },
            nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
            prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
            nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
            prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) - 1 },
            close() {
                document.body.classList.remove('overflow-y-hidden')
                $wire.{{ $closeAction }}()
            },
        }"
        x-init="
            document.body.classList.add('overflow-y-hidden');
            setTimeout(() => firstFocusable()?.focus(), 100);
            return () => document.body.classList.remove('overflow-y-hidden')
        "
        x-on:keydown.escape.window="close()"
        x-on:keydown.tab.prevent="!$event.shiftKey && nextFocusable().focus()"
        x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
    >
        <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="close()"></div>
        <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
            <x-dialog-shell :title="$title" :description="$description" title-id="notification-channel-quick-add-title" max-width="2xl">
                <x-slot name="dismiss">
                    <x-secondary-button type="button" x-on:click="close()">
                        {{ __('Close') }}
                    </x-secondary-button>
                </x-slot>

                <div id="notification-channel-quick-add-title">
                    @if ($types === [])
                        <x-empty-state
                            :title="__('No notification channel types are enabled right now.')"
                            :description="null"
                            class="text-sm"
                        />
                    @else
                        <div class="grid gap-4 lg:grid-cols-3">
                            @if ($canManageOrganizationNotificationChannels)
                                <div>
                                    <x-input-label for="quick_new_owner_scope" :value="__('Owner')" />
                                    <x-select id="quick_new_owner_scope" wire:model.live="quick_new_owner_scope">
                                        <option value="organization">{{ __('Organization') }}</option>
                                        <option value="personal">{{ __('Personal') }}</option>
                                    </x-select>
                                    @error('quick_new_owner_scope')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif
                            <div>
                                <x-input-label for="quick_new_type" :value="__('Type')" />
                                <x-select id="quick_new_type" wire:model.live="quick_new_type">
                                    @foreach ($types as $type)
                                        <option value="{{ $type }}">{{ \App\Models\NotificationChannel::labelForType($type) }}</option>
                                    @endforeach
                                </x-select>
                                @error('quick_new_type')
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <x-input-label for="quick_new_label" :value="__('Label')" />
                                <x-text-input
                                    id="quick_new_label"
                                    type="text"
                                    wire:model="quick_new_label"
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

                <x-slot name="footer">
                    <x-secondary-button type="button" x-on:click="close()">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="button" wire:click="{{ $createAction }}" wire:loading.attr="disabled" wire:target="{{ $createAction }}">
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0 opacity-90" />
                        <span wire:loading.remove wire:target="{{ $createAction }}">{{ __('Create channel') }}</span>
                        <span wire:loading wire:target="{{ $createAction }}">{{ __('Creating…') }}</span>
                    </x-primary-button>
                </x-slot>
            </x-dialog-shell>
        </div>
    </div>
@endif
