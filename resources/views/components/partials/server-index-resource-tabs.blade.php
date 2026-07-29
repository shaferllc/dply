@props([
    /** @var \App\Support\Servers\ServerIndexRow $server */
    'server',
])

@php
    $siteCount = count($server->sites) > 0 ? count($server->sites) : $server->sitesCount;
    $serviceCount = count($server->services);
    $relatedCount = count($server->related);

    $engineLabel = static fn (?string $engine): string => match (strtolower((string) $engine)) {
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'postgres', 'postgresql' => 'PostgreSQL',
        'redis' => 'Redis',
        'valkey' => 'Valkey',
        'keydb' => 'KeyDB',
        'memcached' => 'Memcached',
        default => ucfirst((string) $engine),
    };
    $serviceStatusTone = static fn (?string $status): ?string => match ((string) $status) {
        'running' => null,
        'failed' => 'danger',
        'stopped' => 'info',
        'pending', 'installing', 'uninstalling' => 'warning',
        default => null,
    };

    $tabBtnBase = 'inline-flex min-h-8 items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold shadow-sm transition';
    $panelBox = 'divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10 bg-white';
@endphp

@if ($siteCount > 0 || $serviceCount > 0 || $relatedCount > 0)
    <div x-data="{ tab: '' }" class="min-w-0">
        <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
            @if ($siteCount > 0)
                <button
                    type="button"
                    @click="tab = (tab === 'sites' ? '' : 'sites')"
                    x-bind:aria-expanded="tab === 'sites'"
                    class="{{ $tabBtnBase }}"
                    x-bind:class="tab === 'sites' ? 'border-brand-ink/15 bg-brand-sand/60 text-brand-ink' : 'border-brand-ink/10 bg-white text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink'"
                >
                    <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ trans_choice(':count site|:count sites', $siteCount, ['count' => $siteCount]) }}
                    <span class="transition-transform" x-bind:class="{ 'rotate-180': tab === 'sites' }">
                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    </span>
                </button>
            @endif
            @if ($serviceCount > 0)
                <button
                    type="button"
                    @click="tab = (tab === 'services' ? '' : 'services')"
                    x-bind:aria-expanded="tab === 'services'"
                    class="{{ $tabBtnBase }}"
                    x-bind:class="tab === 'services' ? 'border-brand-ink/15 bg-brand-sand/60 text-brand-ink' : 'border-brand-ink/10 bg-white text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink'"
                >
                    <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ trans_choice(':count service|:count services', $serviceCount, ['count' => $serviceCount]) }}
                    <span class="transition-transform" x-bind:class="{ 'rotate-180': tab === 'services' }">
                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    </span>
                </button>
            @endif
            @if ($relatedCount > 0)
                <button
                    type="button"
                    @click="tab = (tab === 'related' ? '' : 'related')"
                    x-bind:aria-expanded="tab === 'related'"
                    class="{{ $tabBtnBase }}"
                    x-bind:class="tab === 'related' ? 'border-brand-ink/15 bg-brand-sand/60 text-brand-ink' : 'border-brand-ink/10 bg-white text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink'"
                >
                    <x-heroicon-o-share class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ trans_choice(':count related|:count related', $relatedCount, ['count' => $relatedCount]) }}
                    <span class="transition-transform" x-bind:class="{ 'rotate-180': tab === 'related' }">
                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    </span>
                </button>
            @endif
        </div>

        <div x-show="tab !== ''" x-collapse x-cloak class="mt-2">
            @if (count($server->sites) > 0)
                <ul x-show="tab === 'sites'" x-cloak class="{{ $panelBox }}">
                    @foreach ($server->sites as $site)
                        @php
                            $siteStatusTone = ($site['is_failed'] ?? false)
                                ? 'danger'
                                : (($site['is_provisioning'] ?? false) ? 'warning' : (($site['is_ready'] ?? false) ? 'success' : 'info'));
                            $siteSslTone = match ($site['ssl_status'] ?? null) {
                                \App\Models\Site::SSL_ACTIVE => 'success',
                                \App\Models\Site::SSL_PENDING => 'warning',
                                \App\Models\Site::SSL_FAILED => 'danger',
                                default => null,
                            };
                            $href = $site['href'] ?? '#';
                            $external = $server->manageExternal;
                        @endphp
                        <li>
                            <a href="{{ $href }}" @if ($external) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="block px-3 py-2.5 transition hover:bg-brand-sand/30">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="inline-flex min-w-0 items-center gap-2 text-xs font-semibold text-brand-ink">
                                        <x-entity-avatar :seed="$site['name'] ?? ''" :image="$site['logo_url'] ?? null" rounded="rounded-md" class="h-6 w-6 text-[10px]" />
                                        <span class="truncate">{{ $site['name'] ?? '' }}</span>
                                    </span>
                                    <span class="flex shrink-0 items-center gap-1">
                                        <x-badge size="sm" :tone="$siteStatusTone">{{ $site['status_label'] ?? $site['status'] ?? '' }}</x-badge>
                                        @if ($siteSslTone !== null)
                                            <x-badge size="sm" :tone="$siteSslTone">{{ __('SSL') }}</x-badge>
                                        @endif
                                    </span>
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-brand-moss">
                                    @if (! empty($site['type_label']))
                                        <span class="inline-flex items-center gap-1">
                                            <x-heroicon-o-cpu-chip class="h-3 w-3 shrink-0 text-brand-sage" aria-hidden="true" />
                                            {{ $site['type_label'] }}
                                        </span>
                                        <span class="text-brand-mist">·</span>
                                    @endif
                                    @if (! empty($site['runtime_chip']))
                                        <span>{{ $site['runtime_chip'] }}</span>
                                        <span class="text-brand-mist">·</span>
                                    @endif
                                    @if (! empty($site['last_deploy_human']))
                                        <span class="inline-flex items-center gap-1">
                                            <x-heroicon-o-rocket-launch class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                            {{ __('Deployed :ago', ['ago' => $site['last_deploy_human']]) }}
                                        </span>
                                    @else
                                        <span>{{ __('Not deployed yet') }}</span>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($serviceCount > 0)
                <ul x-show="tab === 'services'" x-cloak class="{{ $panelBox }}">
                    @foreach ($server->services as $service)
                        @php $tone = $serviceStatusTone($service['status'] ?? null); @endphp
                        <li class="flex items-center justify-between gap-3 px-3 py-2.5">
                            <span class="inline-flex min-w-0 items-center gap-2">
                                @if (($service['kind'] ?? '') === 'cache')
                                    <x-heroicon-o-bolt class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                @else
                                    <x-heroicon-o-circle-stack class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                @endif
                                <span class="truncate text-xs font-semibold text-brand-ink">{{ $engineLabel($service['engine'] ?? null) }}</span>
                                @if (! empty($service['version']))
                                    <span class="font-mono text-[11px] text-brand-moss">{{ $service['version'] }}</span>
                                @endif
                                @if (! empty($service['is_default']))
                                    <span class="rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Default') }}</span>
                                @endif
                            </span>
                            <span class="flex shrink-0 items-center gap-1">
                                <span class="text-[11px] uppercase tracking-wide text-brand-mist">{{ ($service['kind'] ?? '') === 'cache' ? __('Cache') : __('Database') }}</span>
                                @if ($tone !== null)
                                    <x-badge size="sm" :tone="$tone">{{ ucfirst((string) ($service['status'] ?? '')) }}</x-badge>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($relatedCount > 0)
                <ul x-show="tab === 'related'" x-cloak class="{{ $panelBox }}">
                    @foreach ($server->related as $peer)
                        <li>
                            <a href="{{ $peer['href'] ?? '#' }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="flex items-center justify-between gap-3 px-3 py-2.5 transition hover:bg-brand-sand/30">
                                <span class="inline-flex min-w-0 items-center gap-2">
                                    <x-heroicon-o-server class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                    <span class="truncate text-xs font-semibold text-brand-ink">{{ $peer['name'] ?? '' }}</span>
                                    @if (! empty($peer['ip_address']))
                                        <span class="font-mono text-[11px] text-brand-moss">{{ $peer['ip_address'] }}</span>
                                    @endif
                                </span>
                                <span class="shrink-0 rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                                    {{ $peer['reason'] ?? '' }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
