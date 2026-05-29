<div class="space-y-6">
    {{-- ─────────────────────────────────────────────────────────────
        Edge delivery — read-only backend / hostname info
       ───────────────────────────────────────────────────────────── --}}
    <section id="edge-delivery-backend" class="scroll-mt-24 dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
            </span>
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

    {{-- ─────────────────────────────────────────────────────────────
        Hybrid origin — dply.yaml panel + dashboard form + test
       ───────────────────────────────────────────────────────────── --}}
    @if (($edgeRuntimeMode ?? 'static') === 'hybrid' && is_array($edgeOrigin ?? null))
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-arrow-trending-up class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Origin') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Hybrid origin') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Static assets serve from R2; dynamic routes proxy to this origin. `origin:` rules from :file merge with the dashboard form below at deploy time.', ['file' => $sourcePath]) }}
                    </p>
                </div>
                <a
                    href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                    class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-arrow-down-tray class="h-3 w-3" aria-hidden="true" />
                    {{ __('Generate dply.yaml') }}
                </a>
            </div>

            {{-- 1. From dply.yaml --}}
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-baseline justify-between gap-2">
                    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</h4>
                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Repo-managed') }}</span>
                </div>
                @php
                    $repoOriginUrl = is_string($repoOrigin['url'] ?? null) ? (string) $repoOrigin['url'] : '';
                    $repoOriginRoutes = is_array($repoOrigin['routes'] ?? null) ? $repoOrigin['routes'] : [];
                @endphp
                @if ($repoOriginUrl !== '')
                    <div class="mt-2 rounded-lg border border-brand-ink/10 p-3">
                        <dl class="grid grid-cols-1 gap-y-2 text-xs sm:grid-cols-[7rem_1fr]">
                            <dt class="text-brand-mist">{{ __('URL') }}</dt>
                            <dd class="font-mono text-brand-ink break-all">{{ $repoOriginUrl }}</dd>
                            @if ($repoOriginRoutes !== [])
                                <dt class="text-brand-mist">{{ __('Routes') }}</dt>
                                <dd class="font-mono text-brand-ink">{{ implode(', ', $repoOriginRoutes) }}</dd>
                            @endif
                            @if (! empty($repoOrigin['failover_html']))
                                <dt class="text-brand-mist">{{ __('Failover HTML') }}</dt>
                                <dd class="text-brand-moss">{{ __(':bytes bytes', ['bytes' => strlen((string) $repoOrigin['failover_html'])]) }}</dd>
                            @endif
                        </dl>
                    </div>
                @else
                    <p class="mt-2 text-sm text-brand-moss">{{ __('No origin declared in :file. Add an `origin:` block or use the dashboard form below.', ['file' => $sourcePath]) }}</p>
                    <pre class="mt-3 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>origin:
  url: "https://my-app.fly.dev"
  routes:
    - "/api/*"
    - "/_next/data/*"</code></pre>
                @endif
            </div>

            {{-- 2. Dashboard form (the existing SSR origin form) --}}
            @can('update', $site)
                <div class="border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dashboard-managed') }}</h4>
                    <form wire:submit.prevent="saveEdgeHybridOrigin" class="mt-3 space-y-5">
                        <label class="block">
                            <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Origin URL') }}</span>
                            <input type="url" wire:model="buildForm.edge_origin_url" autocomplete="off" spellcheck="false" placeholder="https://my-origin.dply.app" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage" />
                            @error('buildForm.edge_origin_url') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                            @if (! empty($edgeOrigin['managed']))
                                <p class="mt-1 text-xs text-brand-moss">{{ __('This origin was provisioned by dply (managed).') }}</p>
                            @endif
                        </label>
                        <label class="block">
                            <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Proxy routes') }}</span>
                            <textarea wire:model="buildForm.edge_origin_routes" rows="5" spellcheck="false" placeholder="/api/*&#10;/_next/data/*" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage"></textarea>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('One pattern per line. Leading / and * wildcard.') }}</p>
                            @error('buildForm.edge_origin_routes') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                        </label>
                        <label class="block">
                            <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Healthcheck path') }}</span>
                            <input type="text" wire:model="buildForm.edge_origin_healthcheck_path" autocomplete="off" spellcheck="false" placeholder="/" class="mt-1.5 w-full max-w-xs rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage" />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('GET this path before flipping Edge LIVE. 2xx/3xx pass.') }}</p>
                            @error('buildForm.edge_origin_healthcheck_path') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                        </label>
                        <label class="block">
                            <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Failover HTML (optional)') }}</span>
                            <textarea wire:model="buildForm.edge_origin_failover_html" rows="6" spellcheck="false" placeholder="{{ __('Leave blank for the built-in 503 page.') }}" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage"></textarea>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Shown on 5xx/timeout after one auto-retry. ≤32 KB. 503 + Retry-After: 30.') }}</p>
                            @error('buildForm.edge_origin_failover_html') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                        </label>
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveEdgeHybridOrigin" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                            <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgeHybridOrigin" />
                            <span wire:loading.remove wire:target="saveEdgeHybridOrigin">{{ __('Save origin settings') }}</span>
                            <span wire:loading wire:target="saveEdgeHybridOrigin">{{ __('Saving…') }}</span>
                        </button>
                    </form>

                    @if (! empty($edgeOrigin['auth_secret']))
                        <div class="mt-5 rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-4" x-data="{ copied: false }">
                            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Origin auth secret') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Worker attaches as `X-Dply-Origin-Auth` on every proxied request. Have your origin reject requests without it.') }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <input type="password" readonly value="{{ $edgeOrigin['auth_secret'] }}" class="block min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink" onclick="this.select()" />
                                <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40" @click="navigator.clipboard.writeText(@js($edgeOrigin['auth_secret'])); copied = true; setTimeout(() => copied = false, 2000)">
                                    <x-heroicon-o-clipboard class="h-4 w-4" />
                                    <span x-show="!copied">{{ __('Copy') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                </button>
                                <button type="button" wire:click="rotateEdgeHybridOriginSecret" wire:loading.attr="disabled" wire:target="rotateEdgeHybridOriginSecret" wire:confirm="{{ __('Rotate the origin auth secret?') }}" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50">
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                    {{ __('Rotate') }}
                                </button>
                            </div>
                        </div>
                    @endif

                    <div class="mt-5 rounded-lg border border-brand-ink/10 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Purge edge cache by tag') }}</p>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Origin sets `Cache-Tag: foo` or `X-Dply-Cache-Tag: foo` — purging drops indexed entries (~60s propagation).') }}</p>
                        <form wire:submit.prevent="purgeEdgeCacheByTag" class="mt-2 flex flex-wrap items-center gap-2">
                            <input type="text" wire:model="buildForm.edge_cache_purge_tag" autocomplete="off" spellcheck="false" placeholder="article-42" class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage" />
                            <button type="submit" wire:loading.attr="disabled" wire:target="purgeEdgeCacheByTag" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60">
                                <x-heroicon-o-trash class="h-3.5 w-3.5" wire:loading.remove wire:target="purgeEdgeCacheByTag" />
                                <x-spinner variant="ink" size="sm" wire:loading wire:target="purgeEdgeCacheByTag" />
                                <span wire:loading.remove wire:target="purgeEdgeCacheByTag">{{ __('Purge') }}</span>
                                <span wire:loading wire:target="purgeEdgeCacheByTag">{{ __('Purging…') }}</span>
                            </button>
                        </form>
                        @error('buildForm.edge_cache_purge_tag') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>
                </div>
            @else
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Current') }}</h4>
                    <dl class="mt-2 grid grid-cols-1 gap-y-2 text-xs sm:grid-cols-[7rem_1fr]">
                        <dt class="text-brand-mist">{{ __('URL') }}</dt>
                        <dd class="font-mono text-brand-ink break-all">{{ $edgeOrigin['url'] ?? '—' }}</dd>
                        @if (! empty($edgeOrigin['routes']))
                            <dt class="text-brand-mist">{{ __('Routes') }}</dt>
                            <dd class="font-mono text-brand-ink">{{ implode(', ', $edgeOrigin['routes']) }}</dd>
                        @endif
                    </dl>
                </div>
            @endcan

            {{-- 3. Test origin --}}
            <div class="px-6 py-4 sm:px-8">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Verify origin reachability') }}</h4>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Probes the merged origin URL with X-Dply-Origin-Auth (if set). Reports status + latency.') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span wire:loading.inline-flex wire:target="testOrigin" class="inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
                            <x-spinner size="sm" variant="muted" />
                            {{ __('Probing…') }}
                        </span>
                        <button type="button" wire:click="testOrigin" wire:loading.attr="disabled" wire:target="testOrigin" class="rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                            {{ __('Test origin') }}
                        </button>
                    </div>
                </div>
                @if ($originProbe !== null)
                    @php $tone = ($originProbe['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'; @endphp
                    <div class="mt-3 rounded-lg border px-3 py-2 text-xs {{ $tone }}">
                        @if (isset($originProbe['status']))
                            <p>
                                <span class="font-semibold">{{ __('HTTP :status', ['status' => $originProbe['status']]) }}</span> · {{ __(':latency ms', ['latency' => $originProbe['latency_ms'] ?? 0]) }}
                                @if (! empty($originProbe['has_auth_header'])) · <span class="font-mono text-[10px]">{{ __('X-Dply-Origin-Auth attached') }}</span> @endif
                            </p>
                        @endif
                        @if (! empty($originProbe['target'])) <p class="mt-0.5 break-all font-mono text-[10px] opacity-80">{{ $originProbe['target'] }}</p> @endif
                        @if (! empty($originProbe['error'])) <p class="mt-1 text-[11px]">{{ $originProbe['error'] }}</p> @endif
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- ─────────────────────────────────────────────────────────────
        Convert to hybrid (static-only)
       ───────────────────────────────────────────────────────────── --}}
    @if (($edgeRuntimeMode ?? 'static') !== 'hybrid')
        @can('update', $site)
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-arrow-trending-up class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hybrid') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Convert to hybrid SSR') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Point this Edge site at an existing origin so dynamic routes proxy through. Static assets keep serving from R2.') }}</p>
                    </div>
                </div>
                <form wire:submit.prevent="convertEdgeStaticToHybrid" class="space-y-4 px-6 py-5 sm:px-8">
                    <label class="block">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Existing origin URL') }}</span>
                        <input type="url" wire:model="buildForm.edge_convert_origin_url" autocomplete="off" spellcheck="false" placeholder="https://my-origin.dply.app" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Default proxy routes: /api/*, /_next/data/*. Edit after conversion.') }}</p>
                        @error('buildForm.edge_convert_origin_url') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </label>
                    <button type="submit" wire:loading.attr="disabled" wire:target="convertEdgeStaticToHybrid" wire:confirm="{{ __('Convert to hybrid mode? The next deploy will healthcheck the origin before going LIVE.') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                        <x-spinner variant="white" size="sm" wire:loading wire:target="convertEdgeStaticToHybrid" />
                        <span wire:loading.remove wire:target="convertEdgeStaticToHybrid">{{ __('Convert to hybrid') }}</span>
                        <span wire:loading wire:target="convertEdgeStaticToHybrid">{{ __('Converting…') }}</span>
                    </button>
                </form>
            </section>
        @endcan
    @endif

    {{-- ─────────────────────────────────────────────────────────────
        Image optimization — dply.yaml panel + dashboard form + test
       ───────────────────────────────────────────────────────────── --}}
    @can('update', $site)
        @php
            $imagesMeta = is_array($edgeMeta['images'] ?? null) ? $edgeMeta['images'] : [];
            $imageSecret = is_string($imagesMeta['signing_secret'] ?? null) ? (string) $imagesMeta['signing_secret'] : '';
            $repoAllowed = is_array($repoImages['allowed_hosts'] ?? null) ? $repoImages['allowed_hosts'] : [];
        @endphp
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-photo class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Images') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Image optimization') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Resize and reformat images at the edge via Cloudflare Image Resizing. `images.allowed_hosts:` from :file merges with the dashboard list below; the signing secret stays dashboard-only.', ['file' => $sourcePath]) }}</p>
                </div>
                <a href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}" class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40">
                    <x-heroicon-o-arrow-down-tray class="h-3 w-3" aria-hidden="true" />
                    {{ __('Generate dply.yaml') }}
                </a>
            </div>

            {{-- 1. From dply.yaml --}}
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-baseline justify-between gap-2">
                    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</h4>
                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Repo-managed') }}</span>
                </div>
                @if ($repoAllowed !== [])
                    <div class="mt-2 rounded-lg border border-brand-ink/10 p-3">
                        <p class="text-[11px] uppercase tracking-wide text-brand-mist">{{ __('Allowed hosts') }}</p>
                        <p class="mt-1 font-mono text-xs text-brand-ink break-all">{{ implode(', ', $repoAllowed) }}</p>
                    </div>
                @else
                    <p class="mt-2 text-sm text-brand-moss">{{ __('No image hosts declared in :file. Add an `images:` block or use the dashboard form below.', ['file' => $sourcePath]) }}</p>
                    <pre class="mt-3 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>images:
  allowed_hosts:
    - "images.unsplash.com"
    - "cdn.mysite.com"</code></pre>
                @endif
            </div>

            {{-- 2. Effective merged --}}
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Effective (merged)') }}</h4>
                <dl class="mt-2 grid grid-cols-1 gap-y-2 text-xs sm:grid-cols-[7rem_1fr]">
                    <dt class="text-brand-mist">{{ __('Status') }}</dt>
                    <dd>
                        @if ($effectiveImages['enabled'])
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-emerald-900">{{ __('Enabled') }}</span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Disabled — set signing secret') }}</span>
                        @endif
                    </dd>
                    <dt class="text-brand-mist">{{ __('Allowed hosts') }}</dt>
                    <dd class="font-mono text-brand-ink break-all">
                        @if ($effectiveImages['allowed_hosts'] === [])
                            <span class="text-brand-mist">{{ __('(none — image route will 403 every request)') }}</span>
                        @else
                            {{ implode(', ', $effectiveImages['allowed_hosts']) }}
                        @endif
                    </dd>
                </dl>
            </div>

            {{-- 3. Dashboard form --}}
            <div class="border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dashboard-managed') }}</h4>
                <form wire:submit.prevent="saveEdgeImageOptimization" class="mt-3 space-y-5">
                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                        <input type="checkbox" wire:model="buildForm.edge_image_optimization_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                        <span>
                            <span class="font-medium">{{ __('Enable image optimization') }}</span>
                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Adds the /_dply/image route on this site\'s edge hostname.') }}</span>
                        </span>
                    </label>
                    <label class="block">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Allowed source hostnames') }}</span>
                        <textarea wire:model="buildForm.edge_image_allowed_hosts" rows="4" spellcheck="false" placeholder="images.example.com&#10;cdn.example.org" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage"></textarea>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('One per line. Merges with dply.yaml entries.') }}</p>
                        @error('buildForm.edge_image_allowed_hosts') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </label>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveEdgeImageOptimization" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                        <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgeImageOptimization" />
                        <span wire:loading.remove wire:target="saveEdgeImageOptimization">{{ __('Save image settings') }}</span>
                        <span wire:loading wire:target="saveEdgeImageOptimization">{{ __('Saving…') }}</span>
                    </button>
                </form>

                @if ($imageSecret !== '')
                    <div class="mt-5 rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-4" x-data="{ copiedSig: false }">
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Image signing secret') }}</p>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('HMAC-signs /_dply/image URLs. Anyone with this secret can mint valid signed URLs.') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <input type="password" readonly value="{{ $imageSecret }}" class="block min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink" onclick="this.select()" />
                            <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40" @click="navigator.clipboard.writeText(@js($imageSecret)); copiedSig = true; setTimeout(() => copiedSig = false, 2000)">
                                <x-heroicon-o-clipboard class="h-4 w-4" />
                                <span x-show="!copiedSig">{{ __('Copy') }}</span>
                                <span x-show="copiedSig" x-cloak>{{ __('Copied') }}</span>
                            </button>
                            <button type="button" wire:click="rotateEdgeImageSigningSecret" wire:loading.attr="disabled" wire:target="rotateEdgeImageSigningSecret" wire:confirm="{{ __('Rotate the signing secret? Existing pre-signed URLs will 403.') }}" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                {{ __('Rotate') }}
                            </button>
                        </div>
                    </div>
                @endif
            </div>

            {{-- 4. Test image --}}
            <div class="px-6 py-4 sm:px-8">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Verify image route') }}</h4>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Signs a sample URL with your secret, hits /_dply/image on the live site, and reports the result.') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span wire:loading.inline-flex wire:target="testImage" class="inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
                            <x-spinner size="sm" variant="muted" />
                            {{ __('Probing…') }}
                        </span>
                        <button type="button" wire:click="testImage" wire:loading.attr="disabled" wire:target="testImage" class="rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                            {{ __('Test image') }}
                        </button>
                    </div>
                </div>
                @if ($imageProbe !== null)
                    @php $tone = ($imageProbe['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'; @endphp
                    <div class="mt-3 rounded-lg border px-3 py-2 text-xs {{ $tone }}">
                        @if (isset($imageProbe['status'])) <p><span class="font-semibold">{{ __('HTTP :status', ['status' => $imageProbe['status']]) }}</span> · {{ __(':latency ms', ['latency' => $imageProbe['latency_ms'] ?? 0]) }}</p> @endif
                        @if (! empty($imageProbe['target'])) <p class="mt-0.5 break-all font-mono text-[10px] opacity-80">{{ $imageProbe['target'] }}</p> @endif
                        @if (! empty($imageProbe['error'])) <p class="mt-1 text-[11px]">{{ $imageProbe['error'] }}</p> @endif
                        @if (! empty($imageProbe['hint'])) <p class="mt-1 text-[11px] opacity-80">{{ $imageProbe['hint'] }}</p> @endif
                    </div>
                @endif
            </div>
        </section>
    @endcan
</div>
