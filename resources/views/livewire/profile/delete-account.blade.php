<div>
    <x-livewire-validation-errors />

    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Profile'), 'href' => route('profile.edit'), 'icon' => 'user-circle'],
        ['label' => __('Delete account'), 'icon' => 'trash'],
    ]" />

    <div class="max-w-2xl space-y-6">
        <x-page-header
            :title="__('Delete account')"
            :description="__('This will permanently delete your user account, personal settings, and access to organizations you belong to. Organization data may remain for other members. This action cannot be undone.')"
            doc-route="docs.index"
            flush
        />

        <div class="rounded-2xl border border-red-200/80 bg-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8">
                <div class="border-l-4 border-amber-500 bg-amber-50 rounded-r-lg px-4 py-3 mb-6">
                    <p class="text-sm text-amber-950 leading-relaxed">
                        {{ __('You are about to permanently delete your account. Make sure you have exported anything you need. You will be signed out immediately after deletion.') }}
                    </p>
                </div>

                <form wire:submit="deleteAccount" class="space-y-6" autocomplete="on">
                    <div class="sr-only">
                        <label for="delete_autocomplete_username">{{ __('Account email') }}</label>
                        <input
                            id="delete_autocomplete_username"
                            type="email"
                            name="username"
                            autocomplete="username"
                            value="{{ auth()->user()->email }}"
                            readonly
                            tabindex="-1"
                        />
                    </div>
                    <div>
                        <x-input-label for="delete_password" :value="__('Confirm with your password')" />
                        <x-text-input
                            id="delete_password"
                            wire:model="delete_password"
                            type="password"
                            class="mt-1 block w-full max-w-md"
                            placeholder="{{ __('Current password') }}"
                            autocomplete="current-password"
                        />
                        <x-input-error :messages="$errors->get('delete_password')" class="mt-2" />
                    </div>
                    <div class="flex flex-wrap items-center gap-4">
                        <x-danger-button type="submit" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="deleteAccount">{{ __('Permanently delete my account') }}</span>
                            <span wire:loading wire:target="deleteAccount" class="inline-flex items-center justify-center gap-2">
                                <x-spinner variant="white" size="sm" />
                                {{ __('Deleting…') }}
                            </span>
                        </x-danger-button>
                        <a
                            href="{{ route('profile.edit') }}"
                            wire:navigate
                            class="text-sm font-medium text-brand-moss hover:text-brand-ink"
                        >{{ __('Cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
