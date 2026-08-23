{{-- Security: the passkeys block. Extracted so the page layout
     can change without touching the controls. --}}
        {{-- Passkeys --}}
        <div class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-finger-print"
                :title="__('Passkeys')"
                :count="$passkeyCount > 0 ? $passkeyCount : null"
                :note="__('Sign in with your device PIN, fingerprint, or a security key.')"
            />
            <div class="px-3 py-2.5 sm:px-4">
                @error('passkey')
                    <p class="mb-2 text-xs text-red-600">{{ $message }}</p>
                @enderror
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div class="flex-1">
                        <label for="dply-passkey-alias" class="sr-only">{{ __('Passkey name') }}</label>
                        <input
                            id="dply-passkey-alias"
                            type="text"
                            maxlength="255"
                            autocomplete="off"
                            placeholder="{{ __('Name this passkey — e.g. Work laptop') }}"
                            class="h-7 w-full rounded-md border-brand-ink/15 bg-white py-0 px-2.5 text-xs text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        />
                    </div>
                    <button
                        type="button"
                        id="dply-passkey-register-btn"
                        class="inline-flex h-7 shrink-0 items-center gap-1 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Add a passkey') }}
                    </button>
                </div>
                <p id="dply-passkey-register-error" class="mt-1.5 hidden text-xs text-red-700" role="alert"></p>
            </div>

            <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-1.5 sm:px-4">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Registered') }}</p>
            </div>
            @if ($passkeys->isEmpty())
                <div class="px-3 py-3 text-center sm:px-4">
                    <p class="text-xs text-brand-mist">{{ __('No passkeys registered yet.') }}</p>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($passkeys as $cred)
                        <li class="flex items-center justify-between gap-3 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:px-4">
                            <div class="min-w-0 flex-1">
                                <label class="sr-only" for="passkey-alias-{{ $cred->getKey() }}">{{ __('Passkey name') }}</label>
                                <input
                                    id="passkey-alias-{{ $cred->getKey() }}"
                                    type="text"
                                    wire:key="passkey-alias-{{ $cred->getKey() }}"
                                    wire:model="passkeyAliases.{{ $cred->getKey() }}"
                                    wire:blur="savePasskeyAlias(@js($cred->getKey()))"
                                    maxlength="255"
                                    autocomplete="off"
                                    class="block w-full max-w-md border-0 bg-transparent p-0 text-sm font-semibold text-brand-ink focus:ring-0"
                                    placeholder="{{ __('Passkey name') }}"
                                />
                                <p class="mt-0.5 text-xs text-brand-mist">{{ __('Added :time', ['time' => $cred->created_at->diffForHumans()]) }}</p>
                                @error('passkeyAliases.'.$cred->getKey())
                                    <p class="text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('removePasskey', @js([(string) $cred->getKey()]), @js(__('Remove passkey')), @js(__('Remove this passkey? You\'ll need another way to sign in if it was your only method.')), @js(__('Remove')), true)"
                                class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-rose-200 bg-white px-2 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                            >
                                <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Remove') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

