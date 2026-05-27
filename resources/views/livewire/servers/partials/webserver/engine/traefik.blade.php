            @if ($key === 'traefik' && $engine_subtab === 'providers' && $isActive && $engineHasFullControls($key))
                @php $traefikParams = \App\Services\Servers\TraefikStaticConfigOptions::PARAMS; @endphp
                <div
                    class="{{ $card }} p-6 sm:p-8 mb-6"
                    wire:key="traefik-static-config"
                    x-data="{
                        expanded: true,
                        storageKey: @js('dply.traefik-static-expanded:'.$server->id),
                        init() {
                            try {
                                const saved = window.localStorage?.getItem(this.storageKey);
                                if (saved === '0') this.expanded = false;
                            } catch (e) {}
                        },
                        toggle() {
                            this.expanded = !this.expanded;
                            try { window.localStorage?.setItem(this.storageKey, this.expanded ? '1' : '0'); } catch (e) {}
                        },
                    }"
                    x-init="init()"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <button
                            type="button"
                            x-on:click="toggle()"
                            class="group flex min-w-0 flex-1 items-start gap-3 text-left"
                            x-bind:aria-expanded="expanded.toString()"
                        >
                            <x-heroicon-o-chevron-down
                                class="mt-1 h-4 w-4 shrink-0 text-brand-moss transition-transform"
                                x-bind:class="expanded ? '' : '-rotate-90'"
                                aria-hidden="true"
                            />
                            <span class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Traefik static config') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Settings in /etc/traefik/traefik.yml — API + dashboard, log destinations, ACME email + storage. Dynamic config under /etc/traefik/dynamic/*.yml is hot-reloaded automatically and is out of scope here.') }}
                                </p>
                                <p class="mt-2 inline-flex items-center gap-1.5 rounded-md bg-amber-50/70 px-2.5 py-1 text-[11px] font-medium text-amber-900 ring-1 ring-amber-200">
                                    <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5" />
                                    {{ __('Static config requires a Traefik RESTART (not reload). Edge briefly drops connections on save.') }}
                                </p>
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="loadTraefikStaticConfig"
                            wire:loading.attr="disabled"
                            wire:target="loadTraefikStaticConfig"
                            x-show="expanded"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadTraefikStaticConfig" class="inline-flex">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            </span>
                            <span wire:loading wire:target="loadTraefikStaticConfig" class="inline-flex">
                                <x-spinner class="h-3.5 w-3.5" />
                            </span>
                            {{ __('Reload from server') }}
                        </button>
                    </div>

                    <div x-show="expanded" x-cloak>
                        @if ($traefik_static_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $traefik_static_flash }}</div>
                        @endif
                        @if ($traefik_static_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $traefik_static_error }}</pre>
                            </div>
                        @endif

                        @if (! $traefik_static_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadTraefikStaticConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading traefik.yml…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadTraefikStaticConfig">
                                    {{ __('Click "Reload from server" to fetch current values.') }}
                                </span>
                            </p>
                        @else
                            <form wire:submit.prevent="saveTraefikStaticConfig" class="mt-6 space-y-6">
                                <div class="grid gap-5 sm:grid-cols-2">
                                    @foreach ($traefikParams as $paramKey => $meta)
                                        <label class="block">
                                            <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                            <span class="mt-0.5 block font-mono text-[10px] text-brand-mist">{{ $meta['path'] }}</span>
                                            @if ($meta['type'] === 'bool')
                                                <span class="mt-2 inline-flex items-center gap-2">
                                                    <input type="checkbox" value="1"
                                                        wire:model.live="traefik_static_form.{{ $paramKey }}"
                                                        @checked(($traefik_static_form[$paramKey] ?? '0') === '1')
                                                        class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                                    <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                </span>
                                            @else
                                                <input type="text"
                                                    wire:model.lazy="traefik_static_form.{{ $paramKey }}"
                                                    placeholder="{{ $meta['default'] !== '' ? $meta['default'] : '' }}"
                                                    class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                                <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>

                                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="saveTraefikStaticConfig"
                                        @disabled($isDeployer || $actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="saveTraefikStaticConfig" class="inline-flex">
                                            <x-heroicon-o-arrow-path-rounded-square class="h-4 w-4" />
                                        </span>
                                        <span wire:loading wire:target="saveTraefikStaticConfig" class="inline-flex">
                                            <x-spinner variant="cream" class="h-4 w-4" />
                                        </span>
                                        {{ __('Save and restart Traefik') }}
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
