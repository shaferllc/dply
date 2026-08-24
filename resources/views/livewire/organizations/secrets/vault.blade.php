            <section class="border-b border-brand-ink/10">
                {{-- Adding a secret is a decision, not a thing to scroll past: the
                     form lives in a modal behind this button rather than sitting
                     permanently expanded under the list. --}}
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-lock-closed"
                    :title="__('Shared secrets')"
                    :count="count($vaultRows) ?: null"
                    :note="__('Store a value once, then link it onto any site. Values cannot be read back — rotate to replace. They apply on the next deploy, not a standalone env push.')"
                >
                    @can('update', $organization)
                        <x-slot:actions>
                            <button
                                type="button"
                                wire:click="openNewSecretModal"
                                class="inline-flex h-6 items-center gap-1 rounded-lg bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('New secret') }}
                            </button>
                        </x-slot:actions>
                    @endcan
                </x-workspace-panel-head>

                @if ($vaultRows !== [])
                    @php($bulkSelectable = count($vaultRows) > 1)
                    @if ($bulkSelectable)
                    @can('update', $organization)
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-brand-moss">
                                <input
                                    type="checkbox"
                                    class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage"
                                    wire:click="toggleAllVaultSecrets"
                                    @checked(count($selected_secret_ids) === count($vaultRows))
                                />
                                {{ __('Select all') }}
                            </label>

                            @if ($selected_secret_ids !== [])
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs text-brand-moss">
                                        {{ trans_choice('{1} :count selected|[2,*] :count selected', count($selected_secret_ids), ['count' => count($selected_secret_ids)]) }}
                                    </span>
                                    <button type="button" wire:click="clearVaultSelection" class="text-xs text-brand-moss hover:text-brand-ink">
                                        {{ __('Clear') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="promptDeleteSelectedVaultSecrets"
                                        class="inline-flex h-6 items-center rounded-md bg-rose-600 px-2 text-xs font-semibold text-white shadow-sm hover:bg-rose-700"
                                    >
                                        {{ __('Delete selected') }}
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endcan
                    @endif

                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($vaultRows as $row)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 sm:px-4" wire:key="vault-{{ $row['id'] }}">
                                <div class="flex min-w-0 items-start gap-2.5">
                                    @if ($bulkSelectable)
                                    @can('update', $organization)
                                        <input
                                            type="checkbox"
                                            class="mt-0.5 shrink-0 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage"
                                            value="{{ $row['id'] }}"
                                            wire:model.live="selected_secret_ids"
                                            aria-label="{{ __('Select :key', ['key' => $row['key']]) }}"
                                        />
                                    @endcan
                                    @endif
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
                                </div>
                                @can('update', $organization)
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <button
                                            type="button"
                                            wire:click="startRotateVaultSecret('{{ $row['id'] }}')"
                                            class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Rotate') }}
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="promptDeleteVaultSecret('{{ $row['id'] }}')"
                                            class="inline-flex h-6 items-center gap-1 rounded-md border border-rose-200 bg-white px-2 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                                        >
                                            <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Delete') }}
                                        </button>
                                    </div>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="px-3 py-6 text-center sm:px-4">
                        <p class="text-sm text-brand-moss">{{ __('No shared secrets yet.') }}</p>
                        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-mist">
                            {{ __('Store a value here once, then link it from a site\'s Environment page.') }}
                        </p>
                        @can('update', $organization)
                            <button type="button" wire:click="openNewSecretModal" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                {{ __('Add the first one') }} →
                            </button>
                        @endcan
                    </div>
                @endif
            </section>
