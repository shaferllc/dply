@php
    $card = 'dply-card overflow-hidden';
    $labelCls = 'block text-xs font-semibold uppercase tracking-wide text-brand-moss mb-1';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';
    $methodCatalog = [
        'nginx_http' => [
            'name' => __('Nginx HTTP cache'),
            'desc' => __('FastCGI cache for PHP, proxy cache for Node/Octane, open_file_cache for static.'),
            'icon' => 'heroicon-o-bolt',
            'tone' => ['bg' => 'bg-brand-sage/15', 'text' => 'text-brand-forest', 'ring' => 'ring-brand-sage/25'],
        ],
        'varnish' => [
            'name' => __('Varnish (HTTP front)'),
            'desc' => __('Varnish daemon caches in front of the webserver. Backend moves to :8080.'),
            'icon' => 'heroicon-o-server-stack',
            'tone' => ['bg' => 'bg-sky-50', 'text' => 'text-sky-700', 'ring' => 'ring-sky-200'],
        ],
        'opcache' => [
            'name' => __('PHP OPcache'),
            'desc' => __('Opcode cache tuned at the server level for this PHP version.'),
            'icon' => 'heroicon-o-cpu-chip',
            'tone' => ['bg' => 'bg-violet-50', 'text' => 'text-violet-700', 'ring' => 'ring-violet-200'],
        ],
        'lscache' => [
            'name' => __('LSCache (OpenLiteSpeed)'),
            'desc' => __('LiteSpeed-native cache module — vhost-level rules.'),
            'icon' => 'heroicon-o-rocket-launch',
            'tone' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'ring' => 'ring-amber-200'],
        ],
    ];

    $runtimeMode = $site->runtimeTargetMode();
    $runtimeTarget = $site->runtimeTarget();
    $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
    $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
    $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
    $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
    $section = 'caching';
    $routingTab = 'domains';
    $laravel_tab = 'commands';
@endphp

<div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Caching'),
        'currentIcon' => 'bolt',
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-hero-card
                :eyebrow="__('Site')"
                :title="__('Caching')"
                :description="__('Per-site HTTP cache directives, opcode caches, and Varnish toggles. Availability depends on the site runtime and the active webserver.')"
                icon="bolt"
            />

            @if (empty($available))
                <section class="{{ $card }}">
                    <div class="flex items-start gap-3 bg-amber-50 px-5 py-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-100 text-amber-700 ring-amber-200">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('Unavailable') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-amber-950">{{ __('Caching is not available for this site') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-amber-900">{{ __('This site\'s runtime does not expose cache layers dply can manage. Switch runtimes or use the webserver-config editor for advanced cases.') }}</p>
                        </div>
                    </div>
                </section>
            @else
                <form wire:submit.prevent="save" class="space-y-6">
                    {{-- Master toggle --}}
                    <section class="{{ $card }}">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <x-icon-badge>
                                <x-heroicon-o-power class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Master switch') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Enable caching') }}</h2>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Top-level on/off. Individual methods below only apply when this is on.') }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-3 px-6 py-6 sm:px-7">
                            <label class="flex items-center gap-3">
                                <input type="checkbox" wire:model.live="enabled" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                <span class="text-sm font-semibold text-brand-ink">{{ __('Enable caching for this site') }}</span>
                            </label>
                        </div>
                    </section>

                    {{-- Methods --}}
                    <section class="{{ $card }}">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <x-icon-badge>
                                <x-heroicon-o-squares-2x2 class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Layers') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Methods') }}</h2>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Pick which caching layers apply. Availability depends on site type and the server\'s active webserver.') }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-3 px-6 py-6 sm:px-7">
                            @foreach ($available as $methodId)
                                @php
                                    $meta = $methodCatalog[$methodId] ?? ['name' => $methodId, 'desc' => '', 'icon' => 'heroicon-o-bolt', 'tone' => ['bg' => 'bg-brand-sand/40', 'text' => 'text-brand-forest', 'ring' => 'ring-brand-ink/10']];
                                    $isOn = in_array($methodId, $methods, true);
                                @endphp
                                <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 p-4 transition-colors hover:bg-brand-sand/20 {{ $isOn ? 'bg-brand-sand/15' : '' }}">
                                    <input type="checkbox"
                                           @checked($isOn)
                                           wire:click="toggleMethod('{{ $methodId }}')"
                                           class="mt-1 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $meta['tone']['bg'] }} {{ $meta['tone']['text'] }} {{ $meta['tone']['ring'] }}">
                                        <x-dynamic-component :component="$meta['icon']" class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-brand-ink">{{ $meta['name'] }}</p>
                                        <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $meta['desc'] }}</p>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </section>

                    @if (in_array('nginx_http', $methods, true))
                        <section class="{{ $card }}">
                            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                                <x-icon-badge>
                                    <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                                </x-icon-badge>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Nginx') }}</p>
                                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('HTTP cache settings') }}</h2>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                        {{ __('FastCGI + proxy cache TTLs, plus bypass cookies that skip the cache entirely.') }}
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-5 px-6 py-6 sm:px-7">
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div>
                                        <label class="{{ $labelCls }}" for="fcgi_ttl_200">{{ __('FastCGI TTL (200)') }}</label>
                                        <input id="fcgi_ttl_200" type="text" wire:model="nginx_fcgi_ttl_200" class="{{ $inputCls }}">
                                    </div>
                                    <div>
                                        <label class="{{ $labelCls }}" for="fcgi_ttl_404">{{ __('FastCGI TTL (404)') }}</label>
                                        <input id="fcgi_ttl_404" type="text" wire:model="nginx_fcgi_ttl_404" class="{{ $inputCls }}">
                                    </div>
                                    <div>
                                        <label class="{{ $labelCls }}" for="fcgi_min_uses">{{ __('FastCGI min uses') }}</label>
                                        <input id="fcgi_min_uses" type="number" min="1" wire:model="nginx_fcgi_min_uses" class="{{ $inputCls }}">
                                    </div>
                                    <div>
                                        <label class="{{ $labelCls }}" for="proxy_ttl_200">{{ __('Proxy TTL (200)') }}</label>
                                        <input id="proxy_ttl_200" type="text" wire:model="nginx_proxy_ttl_200" class="{{ $inputCls }}">
                                    </div>
                                    <div>
                                        <label class="{{ $labelCls }}" for="proxy_ttl_404">{{ __('Proxy TTL (404)') }}</label>
                                        <input id="proxy_ttl_404" type="text" wire:model="nginx_proxy_ttl_404" class="{{ $inputCls }}">
                                    </div>
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="bypass_cookies">{{ __('Bypass cookies (comma or space separated)') }}</label>
                                    <input id="bypass_cookies" type="text" placeholder="phpsessid, laravel_session" wire:model="bypass_cookies_input" class="{{ $inputCls }}">
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Requests carrying any of these cookies skip the cache. Wildcards are not supported yet.') }}</p>
                                </div>
                            </div>
                        </section>
                    @endif

                    @if (in_array('lscache', $methods, true))
                        <section class="{{ $card }}">
                            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                                <x-icon-badge>
                                    <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                                </x-icon-badge>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('LSCache') }}</p>
                                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('LiteSpeed cache settings') }}</h2>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                        {{ __('Default object TTL for the LSCache module. Per-rule configuration arrives in v2.') }}
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-3 px-6 py-6 sm:px-7">
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="{{ $labelCls }}" for="lscache_ttl">{{ __('Default TTL (seconds)') }}</label>
                                        <input id="lscache_ttl" type="number" min="1" wire:model="lscache_ttl" class="{{ $inputCls }}">
                                    </div>
                                </div>
                                <p class="text-xs text-brand-moss">{{ __('Tune fine-grained behavior via the webserver-config editor in the meantime.') }}</p>
                            </div>
                        </section>
                    @endif

                    @if (in_array('varnish', $methods, true))
                        <section class="{{ $card }}">
                            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                                <x-icon-badge>
                                    <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                                </x-icon-badge>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Varnish') }}</p>
                                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Front cache settings') }}</h2>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                        {{ __('Default object TTL hint passed to the Varnish daemon for this site.') }}
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-3 px-6 py-6 sm:px-7">
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="{{ $labelCls }}" for="varnish_ttl_default">{{ __('Default object TTL') }}</label>
                                        <input id="varnish_ttl_default" type="text" wire:model="varnish_ttl_default" class="{{ $inputCls }}">
                                    </div>
                                </div>
                                <p class="text-xs text-brand-moss">{{ __('Install or remove the Varnish daemon from the server Caches workspace.') }}</p>
                            </div>
                        </section>
                    @endif

                    @if (in_array('opcache', $methods, true))
                        <section class="{{ $card }}">
                            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                                <x-icon-badge>
                                    <x-heroicon-o-cpu-chip class="h-5 w-5" aria-hidden="true" />
                                </x-icon-badge>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('OPcache') }}</p>
                                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('PHP opcode cache') }}</h2>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                        {{ __('Server-level setting shared across every PHP site on this server.') }}
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-3 px-6 py-6 sm:px-7">
                                <p class="text-sm text-brand-moss">{{ __('Tune knobs (memory, JIT, validate_timestamps) from the server-level OPcache profile in the server PHP workspace.') }}</p>
                            </div>
                        </section>
                    @endif

                    <div class="flex justify-end">
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">{{ __('Save and apply') }}</span>
                            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            @endif

            <x-cli-snippet :commands="[
                ['label' => __('Show site caching'), 'command' => 'dply sites:caching:show '.$site->slug],
                ['label' => __('Apply site config'), 'command' => 'dply sites:webserver-config:apply '.$site->slug],
            ]" />
        </main>
    </div>
</div>
