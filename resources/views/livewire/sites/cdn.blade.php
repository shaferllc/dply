@php
    $card = 'dply-card overflow-hidden';
    $labelCls = 'block text-xs font-semibold uppercase tracking-wide text-brand-moss mb-1';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';
    $presets = [
        'standard' => ['name' => __('Standard'), 'desc' => __('Default cache rules. Browser TTL 30 min.'), 'icon' => 'heroicon-o-bolt', 'tone' => ['bg' => 'bg-brand-sage/15', 'text' => 'text-brand-forest', 'ring' => 'ring-brand-sage/25']],
        'aggressive' => ['name' => __('Aggressive'), 'desc' => __('Cache by query string variations. Browser TTL 4 hours.'), 'icon' => 'heroicon-o-rocket-launch', 'tone' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'ring' => 'ring-amber-200']],
        'bypass' => ['name' => __('Bypass'), 'desc' => __('Proxy traffic but do not cache responses. Useful for first-pass migration.'), 'icon' => 'heroicon-o-arrow-right-circle', 'tone' => ['bg' => 'bg-sky-50', 'text' => 'text-sky-700', 'ring' => 'ring-sky-200']],
    ];
    $credentials = $this->credentials;

    $runtimeMode = $site->runtimeTargetMode();
    $runtimeTarget = $site->runtimeTarget();
    $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
    $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
    $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
    $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
    $section = 'cdn';
    $routingTab = 'domains';
    $laravel_tab = 'commands';

    $metrics = $this->site->cdnConfig()['metrics'] ?? [];
    $hitRate = isset($metrics['hit_rate']) && is_numeric($metrics['hit_rate']) ? (float) $metrics['hit_rate'] : null;
    $reqAll = (int) ($metrics['requests_all'] ?? 0);
    $reqCached = (int) ($metrics['requests_cached'] ?? 0);
    $bwAll = (int) ($metrics['bandwidth_all'] ?? 0);
    $bwCached = (int) ($metrics['bandwidth_cached'] ?? 0);
    $lastPolled = $metrics['last_polled_at'] ?? null;
    $metricsError = $metrics['last_error'] ?? null;
    $formatBytes = function (int $bytes): string {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return number_format($value, $value >= 100 ? 0 : 1).' '.$units[$i];
    };
@endphp

<div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('CDN / Edge'),
        'currentIcon' => 'cloud',
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :eyebrow="__('CDN / Edge')"
                :title="__('Edge cache & proxy')"
                :description="__('Put a CDN / edge network in front of this site\'s origin. Manages the proxied DNS record, cache aggressiveness, and one-click hostname purges.')"
                :show-documentation="false"
                flush
                compact
            />

            @if (empty($credentials))
                <section class="{{ $card }}">
                    <div class="flex items-start gap-3 bg-amber-50 px-5 py-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-100 text-amber-700 ring-amber-200">
                            <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('Setup') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-amber-950">{{ __('Connect a CDN provider') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-amber-900">
                                {{ __('No CDN-capable credential connected yet. Add a Cloudflare or Vercel token in Credentials to enable the edge for this site.') }}
                            </p>
                            <div class="mt-3">
                                <a href="{{ route('credentials.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 shadow-sm hover:bg-amber-100">
                                    {{ __('Open Credentials') }} →
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            @else
                <form wire:submit.prevent="save" class="space-y-6">
                    {{-- Master toggle --}}
                    <section class="{{ $card }}">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-power class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Edge switch') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Proxy through the edge') }}</h2>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('When on, the primary hostname resolves to the provider\'s proxy IPs; when off, traffic goes directly to the origin server.') }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-3 px-6 py-6 sm:px-7">
                            <label class="flex items-center gap-3">
                                <input type="checkbox" wire:model.live="enabled" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                <span class="text-sm font-semibold text-brand-ink">{{ __('Edge in front of this site') }}</span>
                            </label>
                            @if ($lastAppliedAt)
                                <p class="text-xs text-brand-moss">{{ __('Last sync:') }} <span class="font-mono text-brand-ink">{{ $lastAppliedAt }}</span></p>
                            @endif
                            @if ($lastError)
                                <p class="text-xs text-rose-700">{{ __('Last error:') }} {{ $lastError }}</p>
                            @endif
                        </div>
                    </section>

                    {{-- Provider config --}}
                    <section class="{{ $card }}">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-cloud class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Provider') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Edge configuration') }}</h2>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('CDN credentials, target zone, hostname mapped through the edge, and the origin IP that the proxy points back to.') }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-5 px-6 py-6 sm:px-7">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="{{ $labelCls }}" for="provider">{{ __('CDN provider') }}</label>
                                    <select id="provider" wire:model.live="provider" class="{{ $inputCls }}">
                                        <option value="cloudflare">{{ __('Cloudflare') }}</option>
                                    </select>
                                    @error('provider') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="credentialId">{{ __('Credential') }}</label>
                                    <select id="credentialId" wire:model="credentialId" class="{{ $inputCls }}">
                                        <option value="">{{ __('— select —') }}</option>
                                        @foreach ($credentials as $cred)
                                            @if ($cred->provider === $provider)
                                                <option value="{{ $cred->id }}">{{ $cred->name }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    @error('credentialId') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="zoneName">{{ __('Zone (apex domain)') }}</label>
                                    <input id="zoneName" type="text" wire:model="zoneName" placeholder="example.com" class="{{ $inputCls }}">
                                    @error('zoneName') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="hostname">{{ __('Site hostname') }}</label>
                                    <input id="hostname" type="text" wire:model="hostname" placeholder="app.example.com" class="{{ $inputCls }}">
                                    @error('hostname') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="{{ $labelCls }}" for="originIp">{{ __('Origin IP') }}</label>
                                    <input id="originIp" type="text" wire:model="originIp" placeholder="203.0.113.10" class="{{ $inputCls }}">
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Defaults to the site server\'s public IP. Change this if you front the origin with a separate load balancer.') }}</p>
                                    @error('originIp') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- Cache preset --}}
                    <section class="{{ $card }}">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-adjustments-horizontal class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Aggressiveness') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cache preset') }}</h2>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Baseline cache behavior applied to every request unless a path rule below overrides it.') }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-3 px-6 py-6 sm:px-7">
                            @foreach ($presets as $key => $meta)
                                @php $isOn = $cachePreset === $key; @endphp
                                <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 p-4 transition-colors hover:bg-brand-sand/20 {{ $isOn ? 'bg-brand-sand/15' : '' }}">
                                    <input type="radio" name="cachePreset" value="{{ $key }}" wire:model="cachePreset"
                                           class="mt-1 h-4 w-4 border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $meta['tone']['bg'] }} {{ $meta['tone']['text'] }} {{ $meta['tone']['ring'] }}">
                                        <x-dynamic-component :component="$meta['icon']" class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-brand-ink">{{ $meta['name'] }}</p>
                                        <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $meta['desc'] }}</p>
                                    </div>
                                </label>
                            @endforeach
                            @error('cachePreset') <p class="text-xs text-rose-700">{{ $message }}</p> @enderror
                        </div>
                    </section>

                    {{-- Path rules --}}
                    <section class="{{ $card }}">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-queue-list class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Overrides') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Path cache rules') }}</h2>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Per-path overrides applied at the edge. Rules are matched top-to-bottom; the first match wins. Bypass skips the cache; cache forces a TTL regardless of origin headers.') }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-4 px-6 py-6 sm:px-7">
                            @if (! empty($rules))
                                <div class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                                    @foreach ($rules as $idx => $rule)
                                        <div class="flex flex-wrap items-center gap-3 px-4 py-2.5">
                                            <span class="font-mono text-xs text-brand-ink flex-1 min-w-[10rem] break-all">{{ $rule['path'] }}</span>
                                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide
                                                {{ $rule['action'] === 'bypass' ? 'bg-amber-100 text-amber-800 ring-1 ring-inset ring-amber-200/70' : 'bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-200/70' }}">
                                                {{ $rule['action'] === 'bypass' ? __('Bypass') : __('Cache') }}
                                            </span>
                                            @if ($rule['action'] === 'cache')
                                                <span class="text-[11px] text-brand-moss">{{ __('TTL') }}: <span class="font-mono text-brand-ink">{{ $rule['ttl'] }}s</span></span>
                                            @endif
                                            <button type="button" wire:click="removeRule({{ $idx }})" class="text-[11px] font-semibold text-rose-700 hover:underline">
                                                {{ __('Remove') }}
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-xs italic text-brand-moss">{{ __('No path rules yet — the cache preset above applies to all paths.') }}</p>
                            @endif

                            <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-5">
                                    <label class="{{ $labelCls }}" for="newRulePath">{{ __('Path prefix') }}</label>
                                    <input id="newRulePath" type="text" wire:model="newRulePath" placeholder="/api/" class="{{ $inputCls }}">
                                </div>
                                <div class="sm:col-span-3">
                                    <label class="{{ $labelCls }}" for="newRuleAction">{{ __('Action') }}</label>
                                    <select id="newRuleAction" wire:model.live="newRuleAction" class="{{ $inputCls }}">
                                        <option value="bypass">{{ __('Bypass') }}</option>
                                        <option value="cache">{{ __('Cache') }}</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="{{ $labelCls }}" for="newRuleTtl">{{ __('TTL (s)') }}</label>
                                    <input id="newRuleTtl" type="number" min="1" wire:model="newRuleTtl" class="{{ $inputCls }}" @disabled($newRuleAction !== 'cache')>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-secondary-button size="sm" type="button" wire:click="addRule" class="w-full">{{ __('Add rule') }}</x-secondary-button>
                                </div>
                            </div>
                        </div>
                    </section>

                    @if ($enabled)
                        {{-- Metrics --}}
                        <section class="{{ $card }}">
                            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                    <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Insights') }}</p>
                                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Last 24 hours') }}</h2>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                        @if ($lastPolled)
                                            {{ __('Polled') }} <span class="font-mono text-brand-ink">{{ $lastPolled }}</span>
                                        @else
                                            {{ __('No snapshot yet — hourly scheduler will populate this, or refresh manually.') }}
                                        @endif
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-4 px-6 py-6 sm:px-7">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                        @if ($metricsError)
                                            <p class="text-xs text-rose-700">{{ __('Last poll error:') }} {{ $metricsError }}</p>
                                        @else
                                            <span></span>
                                        @endif
                                        <button type="button" wire:click="refreshMetrics" class="rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40">
                                            {{ __('Refresh') }}
                                        </button>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        <div class="rounded-xl border border-brand-ink/10 p-3">
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Hit rate') }}</p>
                                            <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $hitRate !== null ? number_format($hitRate * 100, 1).'%' : '—' }}</p>
                                        </div>
                                        <div class="rounded-xl border border-brand-ink/10 p-3">
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Requests') }}</p>
                                            <p class="mt-1 text-xl font-semibold text-brand-ink">{{ number_format($reqAll) }}</p>
                                            <p class="text-[10px] text-brand-moss">{{ number_format($reqCached) }} {{ __('cached') }}</p>
                                        </div>
                                        <div class="rounded-xl border border-brand-ink/10 p-3">
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Bandwidth') }}</p>
                                            <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $formatBytes($bwAll) }}</p>
                                            <p class="text-[10px] text-brand-moss">{{ $formatBytes($bwCached) }} {{ __('cached') }}</p>
                                        </div>
                                        <div class="rounded-xl border border-brand-ink/10 p-3">
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Origin saved') }}</p>
                                            <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $formatBytes($bwCached) }}</p>
                                            <p class="text-[10px] text-brand-moss">{{ __('served from edge') }}</p>
                                        </div>
                                    </div>
                                </div>
                        </section>
                    @endif

                    <div class="flex flex-wrap items-center gap-3">
                        <x-primary-button size="sm" type="submit">{{ __('Save and sync') }}</x-primary-button>
                        <x-secondary-button size="sm" type="button" wire:click="purge" @disabled(! $enabled)>
                            {{ __('Purge cache') }}
                        </x-secondary-button>
                        @if ($lastPurgeAt)
                            <span class="text-[11px] text-brand-moss">{{ __('Last purge:') }} <span class="font-mono text-brand-ink">{{ $lastPurgeAt }}</span></span>
                        @endif
                    </div>
                </form>
            @endif

            <x-cli-snippet :commands="[
                ['label' => __('Show site CDN'), 'command' => 'dply sites:cdn:show '.$site->slug],
                ['label' => __('Purge cache'), 'command' => 'dply sites:cdn:purge '.$site->slug],
            ]" />
        </main>
    </div>
</div>
