<section id="edge-delivery-backend" class="scroll-mt-24 dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Delivery') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Edge delivery') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Where builds are published after each deploy.') }}</p>
        </div>
    </div>
    <dl class="divide-y divide-brand-ink/8 px-6 py-2 text-sm sm:px-8">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
            <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Mode') }}</dt>
            <dd class="min-w-0 flex-1 text-brand-ink">{{ $edgeDeliveryBackendLabel ?? $site->edgeBackendLabel() }}</dd>
        </div>
        @if ($edgeUsesManagedBackend ?? $site->edge_backend === 'dply_edge')
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Publish hostname') }}</dt>
                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $edgeDeliveryHostname ?? $site->edgeHostname() }}</dd>
            </div>
            @if (! empty($edgeWorkerZoneName))
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                    <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Worker zone') }}</dt>
                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ $edgeWorkerZoneName }}</dd>
                </div>
            @endif
        @endif
        @if ($site->usesOrgCloudflareEdge() && $site->edgeProviderCredential)
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Cloudflare account') }}</dt>
                <dd class="min-w-0 flex-1 text-brand-ink">{{ $site->edgeProviderCredential->name }}</dd>
            </div>
        @endif
    </dl>
</section>

@if (($edgeRuntimeMode ?? 'static') !== 'hybrid')
    @can('update', $site)
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hybrid') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Convert to hybrid SSR') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Point this Edge site at an existing origin server (a dply Cloud container, your own VM, etc.) so dynamic routes proxy through. Static assets keep serving from R2.') }}</p>
                </div>
            </div>
            <form wire:submit.prevent="convertEdgeStaticToHybrid" class="space-y-4 px-6 py-5 sm:px-8">
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Existing origin URL') }}</span>
                    <input
                        type="url"
                        wire:model="buildForm.edge_convert_origin_url"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="https://my-origin.dply.app"
                        class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Default proxy routes: /api/*, /_next/data/*. Edit them after conversion.') }}</p>
                    @error('buildForm.edge_convert_origin_url')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="convertEdgeStaticToHybrid"
                    wire:confirm="{{ __('Convert this site to hybrid mode? The next deploy will run the origin healthcheck before going LIVE.') }}"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="white" size="sm" wire:loading wire:target="convertEdgeStaticToHybrid" />
                    <span wire:loading.remove wire:target="convertEdgeStaticToHybrid">{{ __('Convert to hybrid') }}</span>
                    <span wire:loading wire:target="convertEdgeStaticToHybrid">{{ __('Converting…') }}</span>
                </button>
            </form>
        </section>
    @endcan
@endif

@if (($edgeRuntimeMode ?? 'static') === 'hybrid' && is_array($edgeOrigin ?? null))
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Origin') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('SSR origin (hybrid)') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Static assets are served from Edge; dynamic routes proxy to this origin after an R2 miss. Saved changes take effect immediately — the Worker host map is republished on save.') }}</p>
            </div>
        </div>
        @can('update', $site)
            <form wire:submit.prevent="saveEdgeHybridOrigin" class="space-y-5 px-6 py-5 sm:px-8">
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Origin URL') }}</span>
                    <input
                        type="url"
                        wire:model="buildForm.edge_origin_url"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="https://my-origin.dply.app"
                        class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    @error('buildForm.edge_origin_url')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                    @if (! empty($edgeOrigin['managed']))
                        <p class="mt-1 text-xs text-brand-moss">{{ __('This origin was provisioned by dply (managed). You can still point at a different URL if you have one ready.') }}</p>
                    @endif
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Proxy routes') }}</span>
                    <textarea
                        wire:model="buildForm.edge_origin_routes"
                        rows="5"
                        spellcheck="false"
                        placeholder="/api/*&#10;/_next/data/*"
                        class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    ></textarea>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('One pattern per line. Use a leading / and * as a wildcard, e.g. /api/* or /_next/data/*.') }}</p>
                    @error('buildForm.edge_origin_routes')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Healthcheck path') }}</span>
                    <input
                        type="text"
                        wire:model="buildForm.edge_origin_healthcheck_path"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="/"
                        class="mt-1.5 w-full max-w-xs rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('GET this path on the origin before flipping Edge LIVE. 2xx/3xx pass; anything else fails the deploy.') }}</p>
                    @error('buildForm.edge_origin_healthcheck_path')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Failover HTML (optional)') }}</span>
                    <textarea
                        wire:model="buildForm.edge_origin_failover_html"
                        rows="6"
                        spellcheck="false"
                        placeholder="{{ __('Leave blank to use the built-in dply 503 page.') }}"
                        class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    ></textarea>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Shown when the origin returns 5xx or times out (after one auto-retry). Limit 32 KB. Served as HTTP 503 with Retry-After: 30.') }}</p>
                    @error('buildForm.edge_origin_failover_html')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgeHybridOrigin"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgeHybridOrigin" />
                    <span wire:loading.remove wire:target="saveEdgeHybridOrigin">{{ __('Save origin settings') }}</span>
                    <span wire:loading wire:target="saveEdgeHybridOrigin">{{ __('Saving…') }}</span>
                </button>
            </form>

            @can('update', $site)
                <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-8">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Purge edge cache by tag') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('When your origin sets `Cache-Tag: foo,bar` or `X-Dply-Cache-Tag: foo,bar` on a cacheable response, the Worker indexes entries by tag. Purging here drops the indexed entries via Cloudflare KV (takes effect within ~60s of KV propagation). Use `X-Dply-Cache-Tag` if your origin sits behind Cloudflare and `Cache-Tag` never reaches the Worker.') }}</p>
                    <form wire:submit.prevent="purgeEdgeCacheByTag" class="mt-2 flex flex-wrap items-center gap-2">
                        <input
                            type="text"
                            wire:model="buildForm.edge_cache_purge_tag"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="article-42"
                            class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                        />
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="purgeEdgeCacheByTag"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-heroicon-o-trash class="h-4 w-4" wire:loading.remove wire:target="purgeEdgeCacheByTag" />
                            <x-spinner variant="ink" size="sm" wire:loading wire:target="purgeEdgeCacheByTag" />
                            <span wire:loading.remove wire:target="purgeEdgeCacheByTag">{{ __('Purge') }}</span>
                            <span wire:loading wire:target="purgeEdgeCacheByTag">{{ __('Purging…') }}</span>
                        </button>
                    </form>
                    @error('buildForm.edge_cache_purge_tag')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </div>
            @endcan

            @if (! empty($edgeOrigin['auth_secret']))
                <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-8" x-data="{ copied: false }">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Origin auth secret') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Worker attaches this as `X-Dply-Origin-Auth` on every proxied request. Have your origin app reject requests without a matching value so direct origin-URL traffic returns 401 / 403.') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <input
                            type="password"
                            readonly
                            value="{{ $edgeOrigin['auth_secret'] }}"
                            class="block min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink"
                            onclick="this.select()"
                        />
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                            @click="navigator.clipboard.writeText(@js($edgeOrigin['auth_secret'])); copied = true; setTimeout(() => copied = false, 2000)"
                        >
                            <x-heroicon-o-clipboard class="h-4 w-4" />
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="rotateEdgeHybridOriginSecret"
                            wire:loading.attr="disabled"
                            wire:target="rotateEdgeHybridOriginSecret"
                            wire:confirm="{{ __('Rotate the origin auth secret? Requests using the old value will fail at the origin until you update it there.') }}"
                            class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50 dark:border-rose-900/40 dark:bg-zinc-900 dark:text-rose-300"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                            {{ __('Rotate') }}
                        </button>
                    </div>
                </div>
            @endif
        @else
            <dl class="divide-y divide-brand-ink/8 px-6 py-2 text-sm sm:px-8">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                    <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Origin URL') }}</dt>
                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $edgeOrigin['url'] ?? '—' }}</dd>
                </div>
                @if (! empty($edgeOrigin['routes']))
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                        <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Proxy routes') }}</dt>
                        <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ implode(', ', $edgeOrigin['routes']) }}</dd>
                    </div>
                @endif
            </dl>
        @endcan
    </section>
@endif

@can('update', $site)
    @php
        $imagesMeta = is_array($edgeMeta['images'] ?? null) ? $edgeMeta['images'] : [];
        $imageSecret = is_string($imagesMeta['signing_secret'] ?? null) ? (string) $imagesMeta['signing_secret'] : '';
    @endphp
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-photo class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Images') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Image optimization') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Resize and reformat images at the edge via Cloudflare Image Resizing. Generate signed URLs server-side with App\\Services\\Edge\\EdgeImageUrlSigner.') }}</p>
            </div>
        </div>
        <form wire:submit.prevent="saveEdgeImageOptimization" class="space-y-5 px-6 py-5 sm:px-8">
            <label class="flex items-start gap-3 text-sm text-brand-ink">
                <input type="checkbox" wire:model="buildForm.edge_image_optimization_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                <span>
                    <span class="font-medium">{{ __('Enable image optimization') }}</span>
                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Adds the /_dply/image route on this site\'s edge hostname. Requires Cloudflare Image Resizing on the zone.') }}</span>
                </span>
            </label>

            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Allowed source hostnames') }}</span>
                <textarea
                    wire:model="buildForm.edge_image_allowed_hosts"
                    rows="4"
                    spellcheck="false"
                    placeholder="images.example.com&#10;cdn.example.org"
                    class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                ></textarea>
                <p class="mt-1 text-xs text-brand-moss">{{ __('One hostname per line. Only listed hosts may be used as ?url=… sources; otherwise the optimizer would proxy arbitrary images.') }}</p>
                @error('buildForm.edge_image_allowed_hosts')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </label>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="saveEdgeImageOptimization"
                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
            >
                <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgeImageOptimization" />
                <span wire:loading.remove wire:target="saveEdgeImageOptimization">{{ __('Save image settings') }}</span>
                <span wire:loading wire:target="saveEdgeImageOptimization">{{ __('Saving…') }}</span>
            </button>
        </form>

        @if ($imageSecret !== '')
            <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-8" x-data="{ copiedSig: false }">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Image signing secret') }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Used to HMAC-sign /_dply/image URLs. Anyone with this secret can mint valid image URLs against your allowed source hosts.') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <input
                        type="password"
                        readonly
                        value="{{ $imageSecret }}"
                        class="block min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink"
                        onclick="this.select()"
                    />
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                        @click="navigator.clipboard.writeText(@js($imageSecret)); copiedSig = true; setTimeout(() => copiedSig = false, 2000)"
                    >
                        <x-heroicon-o-clipboard class="h-4 w-4" />
                        <span x-show="!copiedSig">{{ __('Copy') }}</span>
                        <span x-show="copiedSig" x-cloak>{{ __('Copied') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="rotateEdgeImageSigningSecret"
                        wire:loading.attr="disabled"
                        wire:target="rotateEdgeImageSigningSecret"
                        wire:confirm="{{ __('Rotate the signing secret? Any pre-signed image URLs you have already rendered will return 403 until re-signed.') }}"
                        class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50 dark:border-rose-900/40 dark:bg-zinc-900 dark:text-rose-300"
                    >
                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                        {{ __('Rotate') }}
                    </button>
                </div>
            </div>
        @endif
    </section>
@endcan
