@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/40 transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $card = 'rounded-xl border border-brand-ink/10 bg-white p-5 shadow-sm';
    $labelCls = 'block text-xs font-semibold uppercase tracking-wide text-brand-moss mb-1';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';
    $presets = [
        'standard' => ['name' => __('Standard'), 'desc' => __('Default cache rules. Browser TTL 30 min.')],
        'aggressive' => ['name' => __('Aggressive'), 'desc' => __('Cache by query string variations. Browser TTL 4 hours.')],
        'bypass' => ['name' => __('Bypass'), 'desc' => __('Proxy traffic but do not cache responses. Useful for first-pass migration.')],
    ];
    $credentials = $this->credentials;
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
            <li class="text-brand-ink font-medium">{{ __('CDN / Edge') }}</li>
        </ol>
    </nav>

    <div class="mb-8 border-b border-brand-ink/10 pb-6">
        <x-page-header
            :title="__('CDN / Edge')"
            :description="__('Put a CDN / edge network in front of this site\'s origin. Manages the proxied DNS record, cache aggressiveness, and one-click hostname purges.')"
            doc-route="docs.index"
            flush
            compact
        />
    </div>

    @if (empty($credentials))
        <div class="{{ $card }}">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Connect a CDN provider') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('No CDN-capable credential connected yet. Add a Cloudflare or Vercel token in Credentials to enable the edge for this site.') }}
            </p>
            <div class="mt-4">
                <a href="{{ route('credentials.index') }}" wire:navigate class="{{ $btnSecondary }}">
                    {{ __('Open Credentials') }} →
                </a>
            </div>
        </div>
    @else
    <form wire:submit.prevent="save" class="space-y-6">
        <div class="{{ $card }}">
            <label class="flex items-center gap-3">
                <input type="checkbox" wire:model.live="enabled" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                <span class="text-sm font-semibold text-brand-ink">{{ __('Edge in front of this site') }}</span>
            </label>
            <p class="mt-1 text-xs text-brand-moss">{{ __('When on, the primary hostname resolves to the provider\'s proxy IPs; when off, traffic goes directly to the origin server.') }}</p>
            @if ($lastAppliedAt)
                <p class="mt-2 text-[11px] text-brand-moss">{{ __('Last sync:') }} <span class="font-mono text-brand-ink">{{ $lastAppliedAt }}</span></p>
            @endif
            @if ($lastError)
                <p class="mt-2 text-xs text-rose-700">{{ __('Last error:') }} {{ $lastError }}</p>
            @endif
        </div>

        <div class="{{ $card }}">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-brand-moss">{{ __('Provider') }}</h3>
            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                <div>
                    <label class="{{ $labelCls }}" for="originIp">{{ __('Origin IP') }}</label>
                    <input id="originIp" type="text" wire:model="originIp" placeholder="203.0.113.10" class="{{ $inputCls }}">
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Defaults to the site server\'s public IP. Change this if you front the origin with a separate load balancer.') }}</p>
                    @error('originIp') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="{{ $card }}">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-brand-moss">{{ __('Cache preset') }}</h3>
            <div class="mt-4 space-y-3">
                @foreach ($presets as $key => $meta)
                    <label class="flex items-start gap-3 rounded-lg border border-brand-ink/10 p-3 hover:bg-brand-sand/30 transition-colors">
                        <input type="radio" name="cachePreset" value="{{ $key }}" wire:model="cachePreset"
                               class="mt-1 h-4 w-4 border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                        <div class="flex-1">
                            <div class="text-sm font-semibold text-brand-ink">{{ $meta['name'] }}</div>
                            <div class="text-xs text-brand-moss">{{ $meta['desc'] }}</div>
                        </div>
                    </label>
                @endforeach
            </div>
            @error('cachePreset') <p class="mt-2 text-xs text-rose-700">{{ $message }}</p> @enderror
        </div>

        @php
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

        @if ($enabled)
            <div class="{{ $card }}">
                <div class="flex items-baseline justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-brand-moss">{{ __('Last 24 hours') }}</h3>
                        @if ($lastPolled)
                            <p class="mt-1 text-[11px] text-brand-moss">{{ __('Polled') }} <span class="font-mono text-brand-ink">{{ $lastPolled }}</span></p>
                        @else
                            <p class="mt-1 text-[11px] text-brand-moss">{{ __('No snapshot yet — hourly scheduler will populate this, or refresh manually.') }}</p>
                        @endif
                    </div>
                    <button type="button" wire:click="refreshMetrics" class="rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40">
                        {{ __('Refresh') }}
                    </button>
                </div>
                @if ($metricsError)
                    <p class="mt-3 text-xs text-rose-700">{{ __('Last poll error:') }} {{ $metricsError }}</p>
                @endif
                <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="rounded-lg border border-brand-ink/10 p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Hit rate') }}</p>
                        <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $hitRate !== null ? number_format($hitRate * 100, 1).'%' : '—' }}</p>
                    </div>
                    <div class="rounded-lg border border-brand-ink/10 p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Requests') }}</p>
                        <p class="mt-1 text-xl font-semibold text-brand-ink">{{ number_format($reqAll) }}</p>
                        <p class="text-[10px] text-brand-moss">{{ number_format($reqCached) }} {{ __('cached') }}</p>
                    </div>
                    <div class="rounded-lg border border-brand-ink/10 p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Bandwidth') }}</p>
                        <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $formatBytes($bwAll) }}</p>
                        <p class="text-[10px] text-brand-moss">{{ $formatBytes($bwCached) }} {{ __('cached') }}</p>
                    </div>
                    <div class="rounded-lg border border-brand-ink/10 p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Origin saved') }}</p>
                        <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $formatBytes($bwCached) }}</p>
                        <p class="text-[10px] text-brand-moss">{{ __('served from edge') }}</p>
                    </div>
                </div>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="{{ $btnPrimary }}">{{ __('Save and sync') }}</button>
            <button type="button" wire:click="purge" class="{{ $btnSecondary }}" @disabled(! $enabled)>
                {{ __('Purge cache') }}
            </button>
            @if ($lastPurgeAt)
                <span class="text-[11px] text-brand-moss">{{ __('Last purge:') }} <span class="font-mono text-brand-ink">{{ $lastPurgeAt }}</span></span>
            @endif
        </div>
    </form>
    @endif
</div>
