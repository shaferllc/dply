{{--
  Dedicated cache host options on Step 3 — engine tiles + network/auth pickers.
  Required: $form, $cacheEngineOptions
--}}
@php
    use App\Models\ServerCacheService;
    use App\Support\Servers\DedicatedCacheServerProvisionConfig;

    $engineCards = [
        'redis' => [
            'label' => __('Redis'),
            'tagline' => __('Default for queues, sessions, and cache'),
            'icon' => 'heroicon-o-bolt',
            'iconWrap' => 'bg-rose-50 text-rose-600 ring-rose-200/80',
            'port' => 6379,
            'recommended' => true,
        ],
        'valkey' => [
            'label' => __('Valkey'),
            'tagline' => __('Open-source Redis fork, BSD licensed'),
            'icon' => 'heroicon-o-bolt',
            'iconWrap' => 'bg-sky-50 text-sky-600 ring-sky-200/80',
            'port' => 6379,
        ],
        'keydb' => [
            'label' => __('KeyDB'),
            'tagline' => __('Multithreaded Redis-compatible engine'),
            'icon' => 'heroicon-o-bolt',
            'iconWrap' => 'bg-violet-50 text-violet-600 ring-violet-200/80',
            'port' => 6379,
        ],
        'dragonfly' => [
            'label' => __('Dragonfly'),
            'tagline' => __('High-performance Redis-compatible store'),
            'icon' => 'heroicon-o-bolt',
            'iconWrap' => 'bg-amber-50 text-amber-600 ring-amber-200/80',
            'port' => 6379,
            'localOnly' => true,
        ],
        'memcached' => [
            'label' => __('Memcached'),
            'tagline' => __('Simple key/value for app object cache'),
            'icon' => 'heroicon-o-archive-box',
            'iconWrap' => 'bg-emerald-50 text-emerald-600 ring-emerald-200/80',
            'port' => 11211,
            'localOnly' => true,
        ],
    ];

    $cacheEngine = (string) $form->cache_service;
    $supportsRemote = DedicatedCacheServerProvisionConfig::engineSupportsRemoteAccess($cacheEngine);
    $supportsAuth = ServerCacheService::engineSupportsAuth($cacheEngine);
    $cachePort = ServerCacheService::defaultPortFor($cacheEngine);
    $networkMode = $form->cache_remote_access ? 'remote' : 'local';
    $authMode = $form->cache_require_password ? 'password' : 'open';
@endphp

<div class="space-y-8">
    {{-- Engine --}}
    <div>
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Cache engine') }}</p>
                <p class="mt-1 text-xs text-brand-mist">{{ __('Pick the engine this host will run. All options install via apt + systemd.') }}</p>
            </div>
            <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Required') }}</span>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($cacheEngineOptions as $option)
                @php
                    $engineId = (string) ($option['id'] ?? '');
                    $card = $engineCards[$engineId] ?? [
                        'label' => $option['label'] ?? $engineId,
                        'tagline' => '',
                        'icon' => 'heroicon-o-bolt',
                        'iconWrap' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
                        'port' => ServerCacheService::defaultPortFor($engineId),
                    ];
                    $selected = $form->cache_service === $engineId;
                    $comingSoon = (bool) ($option['coming_soon'] ?? false);
                    $canRemote = DedicatedCacheServerProvisionConfig::engineSupportsRemoteAccess($engineId);
                    $canAuth = ServerCacheService::engineSupportsAuth($engineId);
                @endphp
                <button
                    type="button"
                    wire:key="dedicated-cache-engine-{{ $engineId }}"
                    @if (! $comingSoon)
                        wire:click="chooseDedicatedCacheEngine('{{ $engineId }}')"
                    @endif
                    wire:loading.attr="disabled"
                    wire:target="chooseDedicatedCacheEngine"
                    aria-pressed="{{ $selected ? 'true' : 'false' }}"
                    @disabled($comingSoon)
                    @class([
                        'group relative flex w-full flex-col items-start rounded-2xl border-2 p-4 text-left transition-all disabled:cursor-wait',
                        'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/10 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $selected && ! $comingSoon,
                        'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => ! $selected && ! $comingSoon,
                        'cursor-not-allowed border-brand-ink/5 bg-brand-sand/20 opacity-80' => $comingSoon,
                    ])
                >
                    @if (! empty($card['recommended']))
                        <span class="mb-2 inline-flex items-center rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-forest ring-1 ring-brand-sage/30">{{ __('Recommended') }}</span>
                    @endif

                    <span class="flex w-full items-start gap-3">
                        <span @class([
                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 transition-colors',
                            $card['iconWrap'],
                        ])>
                            <x-dynamic-component :component="$card['icon']" class="h-5 w-5 shrink-0" aria-hidden="true" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-brand-ink">{{ $card['label'] }}</span>
                                <span class="rounded-full bg-brand-sand/50 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">:{{ $card['port'] }}</span>
                            </span>
                            @if (! empty($card['tagline']))
                                <span class="mt-0.5 block text-xs leading-snug text-brand-moss">{{ $card['tagline'] }}</span>
                            @endif
                        </span>
                    </span>

                    <span class="mt-3 flex flex-wrap gap-1.5">
                        @if ($canRemote)
                            <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-[10px] font-medium text-brand-forest ring-1 ring-brand-ink/10">
                                <x-heroicon-m-arrows-right-left class="h-3 w-3" aria-hidden="true" />
                                {{ __('Cross-server') }}
                            </span>
                        @endif
                        @if ($canAuth)
                            <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-[10px] font-medium text-brand-forest ring-1 ring-brand-ink/10">
                                <x-heroicon-m-lock-closed class="h-3 w-3" aria-hidden="true" />
                                AUTH
                            </span>
                        @endif
                        @if (! empty($card['localOnly']))
                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-medium text-brand-moss ring-1 ring-brand-ink/10">
                                <x-heroicon-m-home class="h-3 w-3" aria-hidden="true" />
                                {{ __('Localhost wizard') }}
                            </span>
                        @endif
                    </span>

                    @if ($comingSoon)
                        <span class="absolute right-3 top-3 inline-flex items-center rounded-full bg-brand-sand/70 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                            {{ __('Soon') }}
                        </span>
                    @elseif ($selected)
                        <span class="absolute right-3 top-3 inline-flex items-center gap-0.5 rounded-full bg-brand-sage px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm">
                            <x-heroicon-m-check class="h-3 w-3" aria-hidden="true" />
                            {{ __('Selected') }}
                        </span>
                    @endif
                </button>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('form.cache_service')" class="mt-3" />

        @if (collect($cacheEngineOptions)->contains(fn (array $row): bool => (bool) ($row['coming_soon'] ?? false)))
            <p class="mt-3 text-xs leading-relaxed text-brand-mist">
                {{ __('Valkey, KeyDB, Dragonfly, and Memcached appear when enabled for your organization.') }}
            </p>
        @endif
    </div>

    {{-- Network access --}}
    <div class="rounded-2xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream/30 via-white to-white p-5 sm:p-6">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Network access') }}</p>
                <p class="mt-1 text-xs leading-relaxed text-brand-mist">
                    {{ __('Who can connect to this cache? App on the same VM uses localhost; separate app servers need a VPC rule.') }}
                </p>
            </div>
        </div>

        <div class="mt-5 grid gap-3 lg:grid-cols-2">
            {{-- Localhost only --}}
            <button
                type="button"
                wire:click="chooseCacheNetworkAccess('local')"
                wire:loading.attr="disabled"
                wire:target="chooseCacheNetworkAccess"
                aria-pressed="{{ $networkMode === 'local' ? 'true' : 'false' }}"
                @class([
                    'flex flex-col rounded-2xl border-2 p-4 text-left transition-all',
                    'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/25 ring-offset-2 ring-offset-white' => $networkMode === 'local',
                    'border-brand-ink/10 bg-white hover:border-brand-sage/30 hover:bg-brand-sand/10' => $networkMode !== 'local',
                ])
            >
                <span class="flex items-center gap-2">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/20">
                        <x-heroicon-o-home-modern class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span class="text-sm font-semibold text-brand-ink">{{ __('Localhost only') }}</span>
                </span>
                <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('Bind to 127.0.0.1 — only processes on this server can reach the cache.') }}</p>

                {{-- Mini topology: app + cache on one box --}}
                <div class="mt-4 flex justify-center rounded-xl border border-dashed border-brand-ink/10 bg-brand-sand/20 px-4 py-5" aria-hidden="true">
                    <div class="flex flex-col items-center gap-2">
                        <div class="flex items-center gap-2 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <x-heroicon-o-server-stack class="h-5 w-5 text-brand-forest" />
                            <div class="text-left">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('This server') }}</p>
                                <p class="text-xs font-medium text-brand-ink">{{ __('App + :engine', ['engine' => $engineCards[$cacheEngine]['label'] ?? $cacheEngine]) }}</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-[10px] font-medium text-brand-moss ring-1 ring-brand-ink/10">
                            <x-heroicon-m-arrow-down class="h-3 w-3" />
                            127.0.0.1:{{ $cachePort }}
                        </span>
                    </div>
                </div>
            </button>

            {{-- Other servers --}}
            @php
                $remoteDisabled = ! $supportsRemote;
            @endphp
            <button
                type="button"
                @if (! $remoteDisabled)
                    wire:click="chooseCacheNetworkAccess('remote')"
                @endif
                wire:loading.attr="disabled"
                wire:target="chooseCacheNetworkAccess"
                aria-pressed="{{ $networkMode === 'remote' ? 'true' : 'false' }}"
                @disabled($remoteDisabled)
                @class([
                    'flex flex-col rounded-2xl border-2 p-4 text-left transition-all',
                    'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/25 ring-offset-2 ring-offset-white' => $networkMode === 'remote' && ! $remoteDisabled,
                    'border-brand-ink/10 bg-white hover:border-brand-sage/30 hover:bg-brand-sand/10' => $networkMode !== 'remote' && ! $remoteDisabled,
                    'cursor-not-allowed border-brand-ink/5 bg-brand-sand/10 opacity-70' => $remoteDisabled,
                ])
            >
                <span class="flex items-center gap-2">
                    <span @class([
                        'flex h-8 w-8 items-center justify-center rounded-lg ring-1',
                        'bg-brand-sage/15 text-brand-forest ring-brand-sage/20' => ! $remoteDisabled,
                        'bg-brand-sand/50 text-brand-mist ring-brand-ink/10' => $remoteDisabled,
                    ])>
                        <x-heroicon-o-arrows-right-left class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span class="text-sm font-semibold text-brand-ink">{{ __('Other servers on my network') }}</span>
                    @if ($remoteDisabled)
                        <span class="rounded-full bg-brand-sand/70 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Not for this engine') }}</span>
                    @endif
                </span>
                <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                    @if ($remoteDisabled)
                        {{ __('Cross-server firewall rules are wired for Redis, Valkey, and KeyDB. Switch engine above to enable.') }}
                    @else
                        {{ __('Listen on all interfaces, open port :port, and add a UFW allow rule for a trusted CIDR.', ['port' => $cachePort]) }}
                    @endif
                </p>

                {{-- Mini topology: two VMs + firewall --}}
                <div class="mt-4 flex justify-center rounded-xl border border-dashed border-brand-ink/10 bg-brand-sand/20 px-3 py-5" aria-hidden="true">
                    <div class="flex flex-wrap items-center justify-center gap-2 sm:gap-3">
                        <div class="flex flex-col items-center rounded-lg border border-brand-ink/10 bg-white px-3 py-2 shadow-sm">
                            <x-heroicon-o-cpu-chip class="h-4 w-4 text-brand-forest" />
                            <span class="mt-1 text-[10px] font-semibold text-brand-ink">{{ __('App VM') }}</span>
                        </div>
                        <div class="flex flex-col items-center gap-0.5 text-brand-mist">
                            <x-heroicon-m-arrow-right class="h-4 w-4" />
                            <span class="inline-flex items-center gap-0.5 rounded bg-amber-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200/80">
                                <x-heroicon-m-shield-check class="h-3 w-3" />
                                UFW
                            </span>
                        </div>
                        <div class="flex flex-col items-center rounded-lg border border-brand-ink/10 bg-white px-3 py-2 shadow-sm">
                            <x-heroicon-o-bolt class="h-4 w-4 text-rose-600" />
                            <span class="mt-1 text-[10px] font-semibold text-brand-ink">{{ __('Cache VM') }}</span>
                            <span class="font-mono text-[9px] text-brand-mist">:{{ $cachePort }}</span>
                        </div>
                    </div>
                </div>
            </button>
        </div>

        @if ($networkMode === 'remote' && $supportsRemote)
            <div class="mt-4 rounded-xl border border-brand-sage/25 bg-white p-4">
                <x-input-label for="cache_allowed_from" :value="__('Allow from (CIDRs / IPs)')" />
                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-start">
                    <x-text-input
                        id="cache_allowed_from"
                        wire:model.live.debounce.400ms="form.cache_allowed_from"
                        type="text"
                        @class([
                            'block w-full font-mono text-sm sm:max-w-sm',
                            'border-amber-400 ring-amber-200/80' => $form->cache_allowed_from === '' || ! \App\Support\Servers\DedicatedCacheServerProvisionConfig::isAllowedSourceCidr($form->cache_allowed_from),
                        ])
                        placeholder="10.0.0.0/8, 203.0.113.42/32"
                        autocomplete="off"
                    />
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] as $exampleCidr)
                            @php
                                $current = trim((string) $form->cache_allowed_from);
                                $nextAllowedFrom = $current === '' ? $exampleCidr : $current.', '.$exampleCidr;
                            @endphp
                            <button
                                type="button"
                                wire:click="$set('form.cache_allowed_from', @js($nextAllowedFrom))"
                                class="rounded-full bg-brand-sand/40 px-2.5 py-1 font-mono text-[11px] font-medium text-brand-forest transition hover:bg-brand-sage/15 hover:ring-1 hover:ring-brand-sage/30"
                            >
                                + {{ $exampleCidr }}
                            </button>
                        @endforeach
                        @if (! empty($operatorPublicIp ?? null))
                            @php
                                $currentForIp = trim((string) $form->cache_allowed_from);
                                $nextWithIp = $currentForIp === '' ? $operatorPublicIp.'/32' : $currentForIp.', '.$operatorPublicIp.'/32';
                            @endphp
                            <button
                                type="button"
                                wire:click="$set('form.cache_allowed_from', @js($nextWithIp))"
                                class="rounded-full bg-emerald-50 px-2.5 py-1 font-mono text-[11px] font-medium text-emerald-800 ring-1 ring-emerald-200 transition hover:bg-emerald-100"
                                title="{{ __('Add this browser\'s current public IP') }}"
                            >
                                + {{ __('your IP') }} ({{ $operatorPublicIp }}/32)
                            </button>
                        @endif
                    </div>
                </div>
                <p class="mt-2 text-xs text-brand-mist">{{ __('Comma- or space-separated. Each entry can be an IP (203.0.113.42) or CIDR (10.0.0.0/8). Private ranges work too. 0.0.0.0/0 is blocked from this wizard — to open to the world, set the rule manually after provision.') }}</p>
                @if ($form->cache_allowed_from === '')
                    <p class="mt-2 inline-flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-snug text-amber-950 ring-1 ring-amber-200/80">
                        <x-heroicon-m-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Required: pick a CIDR above or switch to Localhost only to continue.') }}
                    </p>
                @endif
                <x-input-error :messages="$errors->get('form.cache_allowed_from')" class="mt-1" />
            </div>
        @endif
    </div>

    {{-- Authentication --}}
    @if ($supportsAuth)
        <div class="rounded-2xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream/30 via-white to-white p-5 sm:p-6">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Authentication') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-mist">
                        {{ __('Require AUTH when other VMs can reach this host — especially on shared VPCs.') }}
                    </p>
                </div>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                <button
                    type="button"
                    wire:click="chooseCacheAuthMode('open')"
                    wire:loading.attr="disabled"
                    wire:target="chooseCacheAuthMode"
                    aria-pressed="{{ $authMode === 'open' ? 'true' : 'false' }}"
                    @class([
                        'flex flex-col rounded-2xl border-2 p-4 text-left transition-all',
                        'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/25 ring-offset-2 ring-offset-white' => $authMode === 'open',
                        'border-brand-ink/10 bg-white hover:border-brand-sage/30 hover:bg-brand-sand/10' => $authMode !== 'open',
                    ])
                >
                    <span class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-sand/50 text-brand-moss ring-1 ring-brand-ink/10">
                            <x-heroicon-o-lock-open class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="text-sm font-semibold text-brand-ink">{{ __('No password') }}</span>
                    </span>
                    <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('Any client that passes the firewall can connect without AUTH.') }}</p>
                    @if ($networkMode === 'remote')
                        <p class="mt-2 inline-flex items-start gap-1.5 rounded-lg bg-amber-50 px-2.5 py-2 text-[11px] leading-snug text-amber-900 ring-1 ring-amber-200/80">
                            <x-heroicon-m-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Remote access without a password is risky on shared networks.') }}
                        </p>
                    @endif
                </button>

                <button
                    type="button"
                    wire:click="chooseCacheAuthMode('password')"
                    wire:loading.attr="disabled"
                    wire:target="chooseCacheAuthMode"
                    aria-pressed="{{ $authMode === 'password' ? 'true' : 'false' }}"
                    @class([
                        'flex flex-col rounded-2xl border-2 p-4 text-left transition-all',
                        'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/25 ring-offset-2 ring-offset-white' => $authMode === 'password',
                        'border-brand-ink/10 bg-white hover:border-brand-sage/30 hover:bg-brand-sand/10' => $authMode !== 'password',
                    ])
                >
                    <span class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/20">
                            <x-heroicon-o-lock-closed class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="text-sm font-semibold text-brand-ink">{{ __('Require password (AUTH)') }}</span>
                        @if ($networkMode === 'remote')
                            <span class="rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-sage/25">{{ __('Recommended') }}</span>
                        @endif
                    </span>
                    <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('dply writes requirepass during provision and stores the value encrypted.') }}</p>
                </button>
            </div>

            @if ($authMode === 'password')
                <div class="mt-4 rounded-xl border border-brand-sage/25 bg-white p-4">
                    <x-password-field
                        id="cache_password"
                        :label="__('Cache password')"
                        wire:model.live="form.cache_password"
                        placeholder="••••••••••••"
                        class="font-mono text-sm"
                    >
                        <x-slot:actions>
                            <button
                                type="button"
                                wire:click="generateDedicatedCachePassword"
                                class="font-medium text-brand-sage hover:underline"
                            >
                                {{ __('Generate') }}
                            </button>
                        </x-slot:actions>
                    </x-password-field>
                    <x-input-error :messages="$errors->get('form.cache_password')" class="mt-1" />
                </div>
            @endif
        </div>
    @elseif ($networkMode === 'remote')
        <div class="rounded-xl border border-amber-200/80 bg-amber-50/60 px-4 py-3 text-xs leading-relaxed text-amber-950">
            <span class="inline-flex items-start gap-2">
                <x-heroicon-m-information-circle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('This engine does not support AUTH in dply yet. Restrict access with a tight CIDR and consider switching to Redis, Valkey, or KeyDB for password support.') }}
            </span>
        </div>
    @endif

    {{-- Live summary --}}
    <div class="rounded-2xl border border-brand-ink/10 bg-brand-ink/[0.02] p-4 sm:p-5">
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Provision preview') }}</p>
        <ul class="mt-3 space-y-2 text-sm text-brand-ink">
            <li class="flex items-start gap-2">
                <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                <span>
                    {{ __('Install :engine on port :port with UFW enabled.', [
                        'engine' => $engineCards[$cacheEngine]['label'] ?? $cacheEngine,
                        'port' => $cachePort,
                    ]) }}
                </span>
            </li>
            <li class="flex items-start gap-2">
                <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                <span>
                    @if ($networkMode === 'remote' && $supportsRemote && $form->cache_allowed_from !== '')
                        {{ __('Bind 0.0.0.0 and allow TCP :port from :cidr.', ['port' => $cachePort, 'cidr' => $form->cache_allowed_from]) }}
                    @elseif ($networkMode === 'remote' && $supportsRemote)
                        {{ __('Bind 0.0.0.0 — pick a source CIDR above.') }}
                    @else
                        {{ __('Bind 127.0.0.1 (localhost only).') }}
                    @endif
                </span>
            </li>
            <li class="flex items-start gap-2">
                @if ($authMode === 'password' && $supportsAuth)
                    <x-heroicon-m-lock-closed class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    <span>{{ __('Password applied at provision time (requirepass).') }}</span>
                @else
                    <x-heroicon-m-lock-open class="mt-0.5 h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                    <span>{{ __('No AUTH password.') }}</span>
                @endif
            </li>
        </ul>
    </div>
</div>
