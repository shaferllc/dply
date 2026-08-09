@php
    $card = 'border-b border-brand-ink/10';
    $panelBody = 'px-5 py-3 sm:px-6';
    $labelCls = 'block text-xs font-semibold text-brand-moss mb-1';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';
    $btnOutline = 'dply-btn dply-btn-xs dply-btn-outline';
    $presets = [
        'standard' => ['name' => __('Standard'), 'desc' => __('Default cache rules. Browser TTL 30 min.'), 'icon' => 'heroicon-o-bolt', 'tone' => ['bg' => 'bg-brand-sage/15', 'text' => 'text-brand-forest', 'ring' => 'ring-brand-sage/25']],
        'aggressive' => ['name' => __('Aggressive'), 'desc' => __('Cache by query string variations. Browser TTL 4 hours.'), 'icon' => 'heroicon-o-rocket-launch', 'tone' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'ring' => 'ring-amber-200']],
        'bypass' => ['name' => __('Bypass'), 'desc' => __('Proxy traffic but do not cache. Useful for first-pass migration.'), 'icon' => 'heroicon-o-arrow-right-circle', 'tone' => ['bg' => 'bg-sky-50', 'text' => 'text-sky-700', 'ring' => 'ring-sky-200']],
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

        <main class="min-w-0 lg:col-span-9">
            <section class="dply-card min-w-0 overflow-hidden p-0">
                @if (empty($credentials))
                    <x-workspace-panel-head
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-cloud"
                        :title="__('CDN / Edge')"
                        :note="__('Edge proxy, cache presets, and one-click purges for this site.')"
                    />

                    <div class="border-b border-brand-ink/10 bg-amber-50/60 px-5 py-3.5 sm:px-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-2.5">
                                <x-heroicon-o-key class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-amber-950">{{ __('Connect a CDN provider') }}</p>
                                    <p class="mt-0.5 text-xs leading-relaxed text-amber-900">{{ __('No CDN-capable credential yet. Add a Cloudflare or Vercel token in Credentials.') }}</p>
                                </div>
                            </div>
                            <a href="{{ route('credentials.index') }}" wire:navigate class="{{ $btnOutline }} shrink-0 !border-amber-300 !bg-white !text-amber-900 hover:!bg-amber-100">
                                {{ __('Open Credentials') }} →
                            </a>
                        </div>
                    </div>
                @else
                    <form wire:submit.prevent="save">
                        <x-workspace-panel-head
                            dense
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-cloud"
                            :title="__('CDN / Edge')"
                            :note="__('Edge proxy, cache presets, and one-click purges for this site.')"
                        >
                            <x-slot:actions>
                                @if ($lastPurgeAt)
                                    <span class="hidden text-2xs text-brand-mist sm:inline">{{ __('Purged') }} <span class="font-mono text-brand-moss">{{ $lastPurgeAt }}</span></span>
                                @endif
                                <button type="button" wire:click="purge" @disabled(! $enabled) class="{{ $btnOutline }}" wire:loading.attr="disabled" wire:target="purge">
                                    <span wire:loading.remove wire:target="purge">{{ __('Purge cache') }}</span>
                                    <span wire:loading wire:target="purge">{{ __('Purging…') }}</span>
                                </button>
                                <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="save">
                                    <span wire:loading.remove wire:target="save">{{ __('Save and sync') }}</span>
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
                                :title="__('Proxy through the edge')"
                                :note="__('On: hostname → provider proxy. Off: traffic goes to the origin.')"
                            />
                            <div class="{{ $panelBody }} space-y-1.5">
                                <label class="flex items-center gap-2.5">
                                    <input type="checkbox" wire:model.live="enabled" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                    <span class="text-xs font-semibold text-brand-ink">{{ __('Edge in front of this site') }}</span>
                                </label>
                                @if ($lastAppliedAt)
                                    <p class="text-xs text-brand-moss">{{ __('Last sync:') }} <span class="font-mono text-brand-ink">{{ $lastAppliedAt }}</span></p>
                                @endif
                                @if ($lastError)
                                    <p class="text-xs text-rose-700">{{ __('Last error:') }} {{ $lastError }}</p>
                                @endif
                            </div>
                        </div>

                        {{-- Provider config --}}
                        <div class="{{ $card }}">
                            <x-workspace-panel-head
                                dense
                                class="border-b border-brand-ink/10"
                                icon="heroicon-o-cloud"
                                :title="__('Edge configuration')"
                                :note="__('Credential, zone, hostname, and origin IP the proxy points back to.')"
                            />
                            <div class="{{ $panelBody }}">
                                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
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
                                        <p class="mt-1 text-xs text-brand-moss">{{ __('Defaults to the server’s public IP. Change if you front a load balancer.') }}</p>
                                        @error('originIp') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Cache preset --}}
                        <div class="{{ $card }}">
                            <x-workspace-panel-head
                                dense
                                class="border-b border-brand-ink/10"
                                icon="heroicon-o-adjustments-horizontal"
                                :title="__('Cache preset')"
                                :note="__('Baseline for every request unless a path rule overrides it.')"
                            />
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($presets as $key => $meta)
                                    @php $isOn = $cachePreset === $key; @endphp
                                    <li>
                                        <label @class([
                                            'flex cursor-pointer items-start gap-2.5 px-5 py-2.5 transition-colors hover:bg-brand-sand/15 sm:px-6',
                                            'bg-brand-sand/[0.08]' => $isOn,
                                        ])>
                                            <input type="radio" name="cachePreset" value="{{ $key }}" wire:model="cachePreset"
                                                   class="mt-0.5 h-4 w-4 border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg ring-1 {{ $meta['tone']['bg'] }} {{ $meta['tone']['text'] }} {{ $meta['tone']['ring'] }}">
                                                <x-dynamic-component :component="$meta['icon']" class="h-3.5 w-3.5" aria-hidden="true" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block text-xs font-semibold text-brand-ink">{{ $meta['name'] }}</span>
                                                <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ $meta['desc'] }}</span>
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                            @error('cachePreset') <p class="px-5 py-2 text-xs text-rose-700 sm:px-6">{{ $message }}</p> @enderror
                        </div>

                        {{-- Path rules --}}
                        <div class="{{ $card }}">
                            <x-workspace-panel-head
                                dense
                                class="border-b border-brand-ink/10"
                                icon="heroicon-o-queue-list"
                                :title="__('Path cache rules')"
                                :note="__('Top-to-bottom; first match wins. Bypass skips cache; cache forces a TTL.')"
                            />
                            <div class="{{ $panelBody }} space-y-2.5">
                                @if (! empty($rules))
                                    <div class="divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 bg-white">
                                        @foreach ($rules as $idx => $rule)
                                            <div class="flex flex-wrap items-center gap-2 px-3 py-2">
                                                <span class="min-w-[10rem] flex-1 break-all font-mono text-xs text-brand-ink">{{ $rule['path'] }}</span>
                                                <span @class([
                                                    'rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1 ring-inset',
                                                    'bg-amber-100 text-amber-800 ring-amber-200/70' => $rule['action'] === 'bypass',
                                                    'bg-emerald-50 text-emerald-800 ring-emerald-200/70' => $rule['action'] !== 'bypass',
                                                ])>
                                                    {{ $rule['action'] === 'bypass' ? __('Bypass') : __('Cache') }}
                                                </span>
                                                @if ($rule['action'] === 'cache')
                                                    <span class="text-xs text-brand-moss">{{ __('TTL') }}: <span class="font-mono text-brand-ink">{{ $rule['ttl'] }}s</span></span>
                                                @endif
                                                <button type="button" wire:click="removeRule({{ $idx }})" class="text-xs font-semibold text-rose-700 hover:underline">
                                                    {{ __('Remove') }}
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-xs italic text-brand-moss">{{ __('No path rules yet — the cache preset applies to all paths.') }}</p>
                                @endif

                                <div class="grid grid-cols-1 items-end gap-2 sm:grid-cols-12">
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
                        </div>

                        @if ($enabled)
                            <div class="{{ $card }}">
                                <x-workspace-panel-head
                                    dense
                                    class="border-b border-brand-ink/10"
                                    icon="heroicon-o-chart-bar"
                                    :title="__('Last 24 hours')"
                                    :note="$lastPolled
                                        ? __('Polled').' '.$lastPolled
                                        : __('No snapshot yet — hourly poll, or refresh manually.')"
                                >
                                    <x-slot:actions>
                                        <button type="button" wire:click="refreshMetrics" class="{{ $btnOutline }}" wire:loading.attr="disabled" wire:target="refreshMetrics">
                                            <span wire:loading.remove wire:target="refreshMetrics">{{ __('Refresh') }}</span>
                                            <span wire:loading wire:target="refreshMetrics">{{ __('Refreshing…') }}</span>
                                        </button>
                                    </x-slot:actions>
                                </x-workspace-panel-head>

                                <div class="{{ $panelBody }} space-y-2">
                                    @if ($metricsError)
                                        <p class="text-xs text-rose-700">{{ __('Last poll error:') }} {{ $metricsError }}</p>
                                    @endif
                                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        <div class="rounded-lg border border-brand-ink/10 px-3 py-2">
                                            <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Hit rate') }}</p>
                                            <p class="mt-0.5 text-base font-semibold text-brand-ink">{{ $hitRate !== null ? number_format($hitRate * 100, 1).'%' : '—' }}</p>
                                        </div>
                                        <div class="rounded-lg border border-brand-ink/10 px-3 py-2">
                                            <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Requests') }}</p>
                                            <p class="mt-0.5 text-base font-semibold text-brand-ink">{{ number_format($reqAll) }}</p>
                                            <p class="text-2xs text-brand-moss">{{ number_format($reqCached) }} {{ __('cached') }}</p>
                                        </div>
                                        <div class="rounded-lg border border-brand-ink/10 px-3 py-2">
                                            <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Bandwidth') }}</p>
                                            <p class="mt-0.5 text-base font-semibold text-brand-ink">{{ $formatBytes($bwAll) }}</p>
                                            <p class="text-2xs text-brand-moss">{{ $formatBytes($bwCached) }} {{ __('cached') }}</p>
                                        </div>
                                        <div class="rounded-lg border border-brand-ink/10 px-3 py-2">
                                            <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Origin saved') }}</p>
                                            <p class="mt-0.5 text-base font-semibold text-brand-ink">{{ $formatBytes($bwCached) }}</p>
                                            <p class="text-2xs text-brand-moss">{{ __('served from edge') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </form>
                @endif

                <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
                    <x-cli-snippet :commands="[
                        ['label' => __('Show site CDN'), 'command' => 'dply sites:cdn:show '.$site->slug],
                        ['label' => __('Purge cache'), 'command' => 'dply sites:cdn:purge '.$site->slug],
                    ]" />
                </div>
            </section>
        </main>
    </div>
</div>
