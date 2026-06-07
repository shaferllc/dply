            @if ($key === 'apache' && $engine_subtab === 'vhosts' && $isActive && $engineHasFullControls($key))
                <div
                    @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'vhosts'" x-cloak @endif
                    class="space-y-4 mb-6"
                    wire:key="apache-custom-vhosts-config"
                >
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('Custom Apache vhosts') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Add ad-hoc VirtualHost blocks as `dply-custom-*.conf` under sites-available. Dply-managed site vhosts are provisioned separately — use this for standalone hostnames, legacy configs, or quick tests.') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="openAddApacheCustomVhostForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4" />
                                    {{ __('Add vhost') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadApacheCustomVhostsConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadApacheCustomVhostsConfig"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadApacheCustomVhostsConfig" class="inline-flex">
                                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="loadApacheCustomVhostsConfig" class="inline-flex">
                                        <x-spinner class="h-4 w-4" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        </div>

                        @if ($apache_custom_vhosts_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $apache_custom_vhosts_flash }}</div>
                        @endif
                        @if ($apache_custom_vhosts_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $apache_custom_vhosts_error }}</pre>
                            </div>
                        @endif

                        @if ($apache_custom_vhosts_show_add)
                            <form wire:submit.prevent="submitAddApacheCustomVhost" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add custom vhost') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Creates sites-available/dply-custom-{slug}.conf, symlinks it into sites-enabled, validates with apachectl configtest, and reloads Apache.') }}</p>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Slug') }}</span>
                                        <input type="text" wire:model.lazy="apache_custom_vhosts_new.slug" placeholder="legacy-api" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" required />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('ServerName') }}</span>
                                        <input type="text" wire:model.lazy="apache_custom_vhosts_new.server_name" placeholder="api.example.com" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" required />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('ServerAlias (optional)') }}</span>
                                        <input type="text" wire:model.lazy="apache_custom_vhosts_new.server_aliases" placeholder="www.example.com" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('DocumentRoot') }}</span>
                                        <input type="text" wire:model.lazy="apache_custom_vhosts_new.document_root" placeholder="/var/www/example/public" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" required />
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('PHP-FPM socket (optional)') }}</span>
                                        <input type="text" wire:model.lazy="apache_custom_vhosts_new.php_socket" placeholder="/run/php/php8.3-fpm.sock" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Leave empty for static-only. Enables PHP via mod_proxy_fcgi when set.') }}</span>
                                    </label>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button type="button" wire:click="cancelAddApacheCustomVhostForm" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="submitAddApacheCustomVhost" @disabled($actionInFlight) class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60">
                                        <span wire:loading.remove wire:target="submitAddApacheCustomVhost" class="inline-flex"><x-heroicon-o-plus class="h-4 w-4" /></span>
                                        <span wire:loading wire:target="submitAddApacheCustomVhost" class="inline-flex"><x-spinner variant="cream" class="h-4 w-4" /></span>
                                        {{ __('Create and reload') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        @if (! $apache_custom_vhosts_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadApacheCustomVhostsConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-4 w-4" /> {{ __('Reading custom vhost files…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadApacheCustomVhostsConfig">
                                    {{ __('Click "Reload from server" to fetch custom vhosts, or add one above.') }}
                                </span>
                            </p>
                        @elseif ($apache_custom_vhosts_form === [])
                            <p class="mt-5 text-sm text-brand-moss">{{ __('No custom vhosts yet — add one above or create a site from the Sites workspace.') }}</p>
                        @endif
                    </div>

                    @if ($apache_custom_vhosts_loaded && $apache_custom_vhosts_form !== [])
                        <div class="space-y-4">
                            @foreach ($apache_custom_vhosts_form as $vhostSlug => $vhostFields)
                                <form wire:submit.prevent="saveApacheCustomVhost(@js($vhostSlug))" class="{{ $card }} p-5 sm:p-6" wire:key="apache-custom-vhost-{{ $vhostSlug }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="font-mono text-sm font-semibold text-brand-ink">dply-custom-{{ $vhostSlug }}.conf</p>
                                            <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('Custom vhost') }}</p>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('removeApacheCustomVhost', [@js($vhostSlug)], @js(__('Remove custom vhost: :slug', ['slug' => $vhostSlug])), @js(__('Delete sites-available/dply-custom-:slug.conf and its sites-enabled symlink?', ['slug' => $vhostSlug])), @js(__('Remove')), true)"
                                            @disabled($isDeployer || $actionInFlight)
                                            class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-[11px] font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <x-heroicon-o-trash class="h-4 w-4" />
                                            {{ __('Remove') }}
                                        </button>
                                    </div>

                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        <label class="block">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('ServerName') }}</span>
                                            <input type="text" wire:model.lazy="apache_custom_vhosts_form.{{ $vhostSlug }}.server_name" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        </label>
                                        <label class="block">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('ServerAlias') }}</span>
                                            <input type="text" wire:model.lazy="apache_custom_vhosts_form.{{ $vhostSlug }}.server_aliases" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        </label>
                                        <label class="block sm:col-span-2">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('DocumentRoot') }}</span>
                                            <input type="text" wire:model.lazy="apache_custom_vhosts_form.{{ $vhostSlug }}.document_root" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        </label>
                                        <label class="block sm:col-span-2">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('PHP-FPM socket') }}</span>
                                            <input type="text" wire:model.lazy="apache_custom_vhosts_form.{{ $vhostSlug }}.php_socket" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                        </label>
                                    </div>

                                    <div class="mt-4 flex justify-end border-t border-brand-ink/10 pt-3">
                                        <button type="submit" wire:loading.attr="disabled" wire:target="saveApacheCustomVhost(@js($vhostSlug))" @disabled($isDeployer || $actionInFlight) class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60">
                                            <span wire:loading.remove wire:target="saveApacheCustomVhost(@js($vhostSlug))" class="inline-flex"><x-heroicon-o-check class="h-4 w-4" /></span>
                                            <span wire:loading wire:target="saveApacheCustomVhost(@js($vhostSlug))" class="inline-flex"><x-spinner variant="cream" class="h-4 w-4" /></span>
                                            {{ __('Save and reload Apache') }}
                                        </button>
                                    </div>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
