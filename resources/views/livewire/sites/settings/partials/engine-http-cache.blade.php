@if ($this->shouldAutoReapplyManagedWebserverConfig())
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
        <div>
            <h3 class="text-base font-semibold text-slate-900">{{ __('Engine HTTP cache') }}</h3>
            <p class="mt-1 text-sm text-slate-600">
                {{ __('Turn on server-level caching for this site. Dply rewrites the managed vhost and reloads the web server; turning it off removes those directives (tear down).') }}
            </p>
        </div>
        <p class="text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
            {{ __('Dynamic pages can be served from cache until it expires—use cautiously with authenticated or personalized content. Prefer cache-friendly headers from your app when in doubt.') }}
        </p>
        <div class="text-sm text-slate-600 space-y-2">
            <p><span class="font-medium text-slate-800">{{ __('Nginx') }}</span> — {{ __('FastCGI cache for PHP-FPM; proxy cache for Octane and Node reverse proxies; open file cache hints for static sites. Shared cache zones are installed under :path.', ['path' => config('sites.nginx_engine_http_cache_conf')]) }}</p>
            <p><span class="font-medium text-slate-800">{{ __('Apache') }}</span> — {{ __('mod_expires browser caching for common static asset types only (not full FastCGI page cache).') }}</p>
            <p><span class="font-medium text-slate-800">{{ __('Caddy, OpenLiteSpeed, Traefik') }}</span> — {{ __('No equivalent full-page cache in this managed path yet; use Nginx or an edge CDN for that workload.') }}</p>
        </div>
        <form wire:submit="saveEngineHttpCache" class="flex flex-wrap items-center gap-4">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" wire:model="engine_http_cache_enabled" class="rounded border-slate-300 text-brand-forest shadow-sm focus:ring-brand-forest" />
                <span class="text-sm font-medium text-slate-900">{{ __('Enable engine HTTP cache') }}</span>
            </label>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEngineHttpCache">
                <span wire:loading.remove wire:target="saveEngineHttpCache">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveEngineHttpCache" class="inline-flex items-center gap-2">
                    <x-spinner variant="cream" class="h-4 w-4" />
                    {{ __('Saving…') }}
                </span>
            </x-primary-button>
        </form>
        <p class="text-xs text-slate-500">{{ __('Responses may include :header for debugging.', ['header' => 'X-Dply-Engine-Cache']) }}</p>
    </div>
@endif
