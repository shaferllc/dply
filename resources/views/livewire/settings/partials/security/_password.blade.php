{{-- Security: the password block. Extracted so the page layout
     can change without touching the controls. --}}
        {{-- Password --}}
        <div class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-lock-closed"
                :title="__('Password')"
                :note="__('Use a long, random password and store it in a password manager.')"
            >
                <x-slot:actions>
                    <p x-show="passwordSaved" x-transition x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700">
                        <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Saved') }}
                    </p>
                </x-slot:actions>
            </x-workspace-panel-head>
            <form wire:submit="updatePassword" autocomplete="on">
                {{-- Hidden username so password managers can pair the new value
                     with the right login. Without this, browsers complain. --}}
                <div class="sr-only">
                    <label for="security_autocomplete_username">{{ __('Account email') }}</label>
                    <input
                        id="security_autocomplete_username"
                        type="email"
                        name="username"
                        autocomplete="username"
                        value="{{ auth()->user()->email }}"
                        readonly
                        tabindex="-1"
                    />
                </div>
                <div class="px-3 py-2.5 sm:px-4">
                    <div class="grid gap-2.5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-input-label for="security_current_password" :value="__('Current password')" />
                            <x-text-input id="security_current_password" wire:model="current_password" type="password" class="mt-1 block w-full" autocomplete="current-password" />
                            <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="security_password" :value="__('New password')" />
                            <x-text-input id="security_password" wire:model="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="security_password_confirmation" :value="__('Confirm new password')" />
                            <x-text-input id="security_password_confirmation" wire:model="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <x-unsaved-changes-bar
            :message="__('You have unsaved changes to your password.')"
            saveAction="updatePassword"
            discardAction="discardPasswordUnsaved"
            targets="current_password,password,password_confirmation"
            :saveLabel="__('Save password')"
        />

