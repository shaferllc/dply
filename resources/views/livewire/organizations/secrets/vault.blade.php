            <section class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-lock-closed"
                    :title="__('Shared secrets')"
                    :note="__('Store a value once, then link it onto any site. Values cannot be read back — rotate to replace. They apply on the next deploy, not a standalone env push.')"
                />

                @if ($vaultRows !== [])
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($vaultRows as $row)
                            <li class="px-3 py-2 sm:px-4" wire:key="vault-{{ $row['id'] }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-mono text-sm font-semibold text-brand-ink">{{ $row['key'] }}</p>
                                        <p class="truncate text-xs text-brand-moss">
                                            {{ $row['notes'] ?: __('No note') }}
                                            <span class="text-brand-mist">·</span>
                                            {{ trans_choice('{0} not linked|{1} :count site|[2,*] :count sites', $row['sites_count'], ['count' => $row['sites_count']]) }}
                                            @if ($row['site_names'] !== [])
                                                <span class="text-brand-mist">({{ implode(', ', $row['site_names']) }})</span>
                                            @endif
                                            <span class="text-brand-mist">· {{ __('write-only') }}</span>
                                        </p>
                                    </div>
                                    @can('update', $organization)
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <button
                                                type="button"
                                                wire:click="startRotateVaultSecret('{{ $row['id'] }}')"
                                                class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                            >
                                                {{ __('Rotate') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex h-6 items-center px-1.5 text-xs font-semibold text-rose-700 hover:text-rose-900"
                                                wire:click="openConfirmActionModal('deleteVaultSecret', @js([$row['id']]), @js(__('Delete secret')), @js(__('Delete :key? It unlinks from every site. Those sites drop the key on the next deploy.', ['key' => $row['key']])), @js(__('Delete')), true)"
                                            >
                                                {{ __('Delete') }}
                                            </button>
                                        </div>
                                    @endcan
                                </div>

                                @if ($rotating_secret_id === $row['id'])
                                    <div class="mt-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-2.5">
                                        <x-input-label for="rotate_value_{{ $row['id'] }}" :value="__('New value')" />
                                        <x-text-input id="rotate_value_{{ $row['id'] }}" type="password" wire:model="rotate_value" class="mt-1 block w-full font-mono" autocomplete="new-password" />
                                        <x-input-error :messages="$errors->get('rotate_value')" class="mt-1" />
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            <x-primary-button type="button" wire:click="rotateVaultSecret" wire:loading.attr="disabled" wire:target="rotateVaultSecret">
                                                {{ __('Save new value') }}
                                            </x-primary-button>
                                            <x-secondary-button type="button" wire:click="cancelRotateVaultSecret">{{ __('Cancel') }}</x-secondary-button>
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="px-3 py-4 text-sm text-brand-moss sm:px-4">{{ __('No shared secrets yet. Create one, then link it from a site\'s Environment page.') }}</p>
                @endif

                @can('update', $organization)
                    <div class="border-t border-brand-ink/10 px-3 py-3 sm:px-4">
                        <h3 class="text-xs font-semibold text-brand-ink">{{ __('Add a secret') }}</h3>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Keys may be reused. Add a note when the key already exists so you can tell them apart.') }}</p>
                        <div class="mt-2 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="vault_key" :value="__('Key')" />
                                <x-text-input id="vault_key" wire:model="vault_key" class="mt-1 block w-full font-mono uppercase" placeholder="STRIPE_SECRET" />
                                <x-input-error :messages="$errors->get('vault_key')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="vault_value" :value="__('Value')" />
                                <x-text-input id="vault_value" type="password" wire:model="vault_value" class="mt-1 block w-full font-mono" autocomplete="new-password" />
                                <x-input-error :messages="$errors->get('vault_value')" class="mt-1" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label for="vault_notes" :value="__('Notes')" />
                                <x-text-input id="vault_notes" wire:model="vault_notes" class="mt-1 block w-full" placeholder="{{ __('Required when this key already exists') }}" />
                                <x-input-error :messages="$errors->get('vault_notes')" class="mt-1" />
                            </div>
                        </div>
                        <div class="mt-2.5">
                            <x-primary-button type="button" wire:click="createVaultSecret" wire:loading.attr="disabled" wire:target="createVaultSecret">
                                {{ __('Save secret') }}
                            </x-primary-button>
                        </div>
                    </div>
                @endcan
            </section>
