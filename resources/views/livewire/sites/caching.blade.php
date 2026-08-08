@php
    $card = 'border-b border-brand-ink/10';
    $panelBody = 'px-5 py-3 sm:px-6';
    $labelCls = 'block text-xs font-semibold text-brand-moss mb-1';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';
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

        <main class="min-w-0 lg:col-span-9">
            <section class="dply-card min-w-0 overflow-hidden p-0">
                @if (empty($available))
                    <x-workspace-panel-head
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-bolt"
                        :title="__('Caching')"
                        :note="__('HTTP cache, opcode cache, and Varnish toggles for this site.')"
                    />

                    <div class="border-b border-brand-ink/10 bg-amber-50/60 px-5 py-3.5 sm:px-6">
                        <div class="flex items-start gap-2.5">
                            <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-amber-950">{{ __('Caching is not available for this site') }}</p>
                                <p class="mt-0.5 text-xs leading-relaxed text-amber-900">{{ __('This site\'s runtime does not expose cache layers dply can manage. Switch runtimes or use the webserver-config editor for advanced cases.') }}</p>
                            </div>
                        </div>
                    </div>
                @else
                    <form wire:submit.prevent="save">
                        <x-workspace-panel-head
                            dense
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-bolt"
                            :title="__('Caching')"
                            :note="__('HTTP cache, opcode cache, and Varnish toggles for this site.')"
                        >
                            <x-slot:actions>
                                <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="save">
                                    <span wire:loading.remove wire:target="save">{{ __('Save and apply') }}</span>
                                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                                </x-primary-button>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        {{-- Master toggle --}}
                        <div class="{{ $card }}">
                            <x-workspace-panel-head
                                dense
                                class="border-b border-brand-ink/10"
                                icon="heroicon-o-power"
                                :title="__('Enable caching')"
                                :note="__('Top-level on/off. Methods below only apply when this is on.')"
                            />
                            <div class="{{ $panelBody }}">
                                <label class="flex items-center gap-2.5">
                                    <input type="checkbox" wire:model.live="enabled" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                    <span class="text-xs font-semibold text-brand-ink">{{ __('Enable caching for this site') }}</span>
                                </label>
                            </div>
                        </div>

                        {{-- Methods --}}
                        <div class="{{ $card }}">
                            <x-workspace-panel-head
                                dense
                                class="border-b border-brand-ink/10"
                                icon="heroicon-o-squares-2x2"
                                :title="__('Methods')"
                                :note="__('Which layers apply. Availability depends on site type and webserver.')"
                            />
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($available as $methodId)
                                    @php
                                        $meta = $methodCatalog[$methodId] ?? ['name' => $methodId, 'desc' => '', 'icon' => 'heroicon-o-bolt', 'tone' => ['bg' => 'bg-brand-sand/40', 'text' => 'text-brand-forest', 'ring' => 'ring-brand-ink/10']];
                                        $isOn = in_array($methodId, $methods, true);
                                    @endphp
                                    <li>
                                        <label @class([
                                            'flex cursor-pointer items-start gap-2.5 px-5 py-2.5 transition-colors hover:bg-brand-sand/15 sm:px-6',
                                            'bg-brand-sand/[0.08]' => $isOn,
                                        ])>
                                            <input type="checkbox"
                                                   @checked($isOn)
                                                   wire:click="toggleMethod('{{ $methodId }}')"
                                                   class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg ring-1 {{ $meta['tone']['bg'] }} {{ $meta['tone']['text'] }} {{ $meta['tone']['ring'] }}">
                                                <x-dynamic-component :component="$meta['icon']" class="h-3.5 w-3.5" aria-hidden="true" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block text-xs font-semibold text-brand-ink">{{ $meta['name'] }}</span>
                                                <span class="mt-0.5 block text-[11px] leading-relaxed text-brand-moss">{{ $meta['desc'] }}</span>
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        @if (in_array('nginx_http', $methods, true))
                            <div class="{{ $card }}">
                                <x-workspace-panel-head
                                    dense
                                    class="border-b border-brand-ink/10"
                                    icon="heroicon-o-bolt"
                                    :title="__('HTTP cache settings')"
                                    :note="__('FastCGI + proxy TTLs, and bypass cookies that skip the cache.')"
                                />
                                <div class="{{ $panelBody }} space-y-3">
                                    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
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
                                        <label class="{{ $labelCls }}" for="bypass_cookies">{{ __('Bypass cookies') }}</label>
                                        <input id="bypass_cookies" type="text" placeholder="phpsessid, laravel_session" wire:model="bypass_cookies_input" class="{{ $inputCls }}">
                                        <p class="mt-1 text-[11px] text-brand-moss">{{ __('Comma or space separated. Requests with these cookies skip the cache.') }}</p>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if (in_array('lscache', $methods, true))
                            <div class="{{ $card }}">
                                <x-workspace-panel-head
                                    dense
                                    class="border-b border-brand-ink/10"
                                    icon="heroicon-o-rocket-launch"
                                    :title="__('LiteSpeed cache settings')"
                                    :note="__('Default object TTL. Per-rule config arrives in v2.')"
                                />
                                <div class="{{ $panelBody }} space-y-2">
                                    <div class="max-w-xs">
                                        <label class="{{ $labelCls }}" for="lscache_ttl">{{ __('Default TTL (seconds)') }}</label>
                                        <input id="lscache_ttl" type="number" min="1" wire:model="lscache_ttl" class="{{ $inputCls }}">
                                    </div>
                                    <p class="text-[11px] text-brand-moss">{{ __('Fine-grained rules: use the webserver-config editor for now.') }}</p>
                                </div>
                            </div>
                        @endif

                        @if (in_array('varnish', $methods, true))
                            <div class="{{ $card }}">
                                <x-workspace-panel-head
                                    dense
                                    class="border-b border-brand-ink/10"
                                    icon="heroicon-o-server-stack"
                                    :title="__('Front cache settings')"
                                    :note="__('Server-wide Varnish — no per-site VCL or TTL here.')"
                                />
                                {{-- No per-site TTL input: VCL is server-wide and honours Cache-Control. --}}
                                <div class="{{ $panelBody }} space-y-2">
                                    <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5">
                                            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Fallback object TTL') }}</dt>
                                            <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ __('120s (server-wide)') }}</dd>
                                        </div>
                                        <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5">
                                            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Per-site TTL') }}</dt>
                                            <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ __('Cache-Control from your app') }}</dd>
                                        </div>
                                    </dl>
                                    <p class="text-[11px] text-brand-moss">{{ __('Send Cache-Control to control TTL per route. Auth cookies / Authorization bypass the cache. Manage the daemon from server Caches.') }}</p>
                                </div>
                            </div>
                        @endif

                        @if (in_array('opcache', $methods, true))
                            <div class="{{ $card }}">
                                <x-workspace-panel-head
                                    dense
                                    class="border-b border-brand-ink/10"
                                    icon="heroicon-o-cpu-chip"
                                    :title="__('PHP opcode cache')"
                                    :note="__('Server-level setting shared across every PHP site on this server.')"
                                />
                                <div class="{{ $panelBody }}">
                                    <p class="text-xs text-brand-moss">{{ __('Tune memory, JIT, and validate_timestamps from the server PHP workspace OPcache profile.') }}</p>
                                </div>
                            </div>
                        @endif
                    </form>
                @endif

                <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
                    <x-cli-snippet :commands="[
                        ['label' => __('Show site caching'), 'command' => 'dply sites:caching:show '.$site->slug],
                        ['label' => __('Apply site config'), 'command' => 'dply sites:webserver-config:apply '.$site->slug],
                    ]" />
                </div>
            </section>
        </main>
    </div>
</div>
