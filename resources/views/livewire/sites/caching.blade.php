@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $card = 'rounded-xl border border-brand-ink/10 bg-white p-5 shadow-sm';
    $labelCls = 'block text-xs font-semibold uppercase tracking-wide text-brand-moss mb-1';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';
    $methodCatalog = [
        'nginx_http' => [
            'name' => __('Nginx HTTP cache'),
            'desc' => __('FastCGI cache for PHP, proxy cache for Node/Octane, open_file_cache for static.'),
        ],
        'varnish' => [
            'name' => __('Varnish (HTTP front)'),
            'desc' => __('Varnish daemon caches in front of the webserver. Backend moves to :8080.'),
        ],
        'opcache' => [
            'name' => __('PHP OPcache'),
            'desc' => __('Opcode cache tuned at the server level for this PHP version.'),
        ],
        'lscache' => [
            'name' => __('LSCache (OpenLiteSpeed)'),
            'desc' => __('LiteSpeed-native cache module — vhost-level rules.'),
        ],
    ];
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <nav class="text-sm text-brand-moss mb-6" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Servers') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[10rem]" title="{{ $server->name }}">{{ $server->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', [$server, $site]) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[10rem]" title="{{ $site->name }}">{{ $site->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('Caching') }}</li>
        </ol>
    </nav>

    <div class="mb-8 border-b border-brand-ink/10 pb-6">
        <x-page-header
            :title="__('Caching')"
            :description="__('Per-site caching: HTTP cache directives, opcode caches, and Varnish toggles.')"
            doc-route="docs.index"
            flush
            compact
        />
    </div>

    @if (empty($available))
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ __('Caching is not available for this site\'s runtime.') }}
        </div>
    @else
    <form wire:submit.prevent="save" class="space-y-6">
        <div class="{{ $card }}">
            <label class="flex items-center gap-3">
                <input type="checkbox" wire:model.live="enabled" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                <span class="text-sm font-semibold text-brand-ink">{{ __('Enable caching for this site') }}</span>
            </label>
            <p class="mt-1 text-xs text-brand-moss">{{ __('Master toggle. Individual methods below only apply when this is on.') }}</p>
        </div>

        <div class="{{ $card }}">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-brand-moss">{{ __('Methods') }}</h3>
            <p class="mt-1 text-xs text-brand-moss">{{ __('Pick which caching layers apply to this site. Availability depends on site type and the server\'s current webserver.') }}</p>
            <div class="mt-4 space-y-3">
                @foreach ($available as $methodId)
                    @php $meta = $methodCatalog[$methodId] ?? ['name' => $methodId, 'desc' => '']; @endphp
                    <label class="flex items-start gap-3 rounded-lg border border-brand-ink/10 p-3 hover:bg-brand-sand/30 transition-colors">
                        <input type="checkbox"
                               @checked(in_array($methodId, $methods, true))
                               wire:click="toggleMethod('{{ $methodId }}')"
                               class="mt-1 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                        <div class="flex-1">
                            <div class="text-sm font-semibold text-brand-ink">{{ $meta['name'] }}</div>
                            <div class="text-xs text-brand-moss">{{ $meta['desc'] }}</div>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>

        @if (in_array('nginx_http', $methods, true))
            <div class="{{ $card }}">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-brand-moss">{{ __('Nginx HTTP cache settings') }}</h3>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
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
                <div class="mt-4">
                    <label class="{{ $labelCls }}" for="bypass_cookies">{{ __('Bypass cookies (comma or space separated)') }}</label>
                    <input id="bypass_cookies" type="text" placeholder="phpsessid, laravel_session" wire:model="bypass_cookies_input" class="{{ $inputCls }}">
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Requests carrying any of these cookies skip the cache. Wildcards are not supported yet.') }}</p>
                </div>
            </div>
        @endif

        @if (in_array('lscache', $methods, true))
            <div class="{{ $card }}">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-brand-moss">{{ __('LSCache settings') }}</h3>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="{{ $labelCls }}" for="lscache_ttl">{{ __('Default TTL (seconds)') }}</label>
                        <input id="lscache_ttl" type="number" min="1" wire:model="lscache_ttl" class="{{ $inputCls }}">
                    </div>
                </div>
                <p class="mt-2 text-xs text-brand-moss">{{ __('Per-rule LSCache configuration arrives in v2. Tune defaults via the webserver-config editor in the meantime.') }}</p>
            </div>
        @endif

        @if (in_array('varnish', $methods, true))
            <div class="{{ $card }}">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-brand-moss">{{ __('Varnish settings') }}</h3>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="{{ $labelCls }}" for="varnish_ttl_default">{{ __('Default object TTL') }}</label>
                        <input id="varnish_ttl_default" type="text" wire:model="varnish_ttl_default" class="{{ $inputCls }}">
                    </div>
                </div>
                <p class="mt-2 text-xs text-brand-moss">{{ __('Install or remove the Varnish daemon from the server Caches workspace.') }}</p>
            </div>
        @endif

        @if (in_array('opcache', $methods, true))
            <div class="{{ $card }}">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-brand-moss">{{ __('PHP OPcache') }}</h3>
                <p class="mt-2 text-xs text-brand-moss">{{ __('OPcache is shared across every PHP site on this server. Tune knobs (memory, JIT, validate_timestamps) from the server-level OPcache profile.') }}</p>
            </div>
        @endif

        <div class="flex justify-end">
            <button type="submit" class="{{ $btnPrimary }}">
                <span wire:loading.remove wire:target="save">{{ __('Save and apply') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    </form>
    @endif
</div>
