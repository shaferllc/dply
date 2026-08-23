{{-- The inputs themselves, no chrome: label, token, and the self-hosted API
     base URL. Wrapped by _pat-form (inline) and _pat-modal (dialog). --}}
                        <div class="grid gap-2.5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="pat-label-{{ $provider['id'] }}" :value="__('Label (optional)')" />
                                <x-text-input id="pat-label-{{ $provider['id'] }}" wire:model="patLabel" class="mt-1 block w-full" placeholder="{{ __('e.g. machine user, work account') }}" />
                                @error('patLabel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="pat-token-{{ $provider['id'] }}" :value="__('Token')" />
                                <x-text-input id="pat-token-{{ $provider['id'] }}" type="password" wire:model="patToken" class="mt-1 block w-full font-mono" autocomplete="off" />
                                @error('patToken') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        @if ($provider['id'] !== 'bitbucket')
                            <div>
                                <x-input-label for="pat-base-{{ $provider['id'] }}" :value="$provider['id'] === 'github' ? __('API base URL (optional, for GitHub Enterprise)') : __('API base URL (optional, for self-hosted GitLab)')" />
                                <x-text-input
                                    id="pat-base-{{ $provider['id'] }}"
                                    wire:model="patApiBaseUrl"
                                    class="mt-1 block w-full font-mono"
                                    placeholder="{{ $provider['id'] === 'github' ? 'https://github.example.com/api/v3' : 'https://gitlab.example.com' }}"
                                />
                                @error('patApiBaseUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Leave blank for the public :host host.', ['host' => $provider['host']]) }}</p>
                            </div>
                        @endif
