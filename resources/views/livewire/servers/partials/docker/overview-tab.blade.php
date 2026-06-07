@php
    $installAction = is_array($serviceActions['install_docker'] ?? null) ? $serviceActions['install_docker'] : null;
    $upgradeAction = is_array($serviceActions['repair_docker'] ?? null) ? $serviceActions['repair_docker'] : null;
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <div class="flex items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-square-3-stack-3d class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Engine') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Docker Engine') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('From the last inventory probe. Open Containers or Maintenance for live SSH data.') }}
                </p>
            </div>
        </div>
        @if ($docker_present)
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-medium text-emerald-800 ring-1 ring-emerald-200">
                <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                {{ __('Installed') }}
            </span>
        @else
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-[11px] font-medium text-brand-moss ring-1 ring-brand-ink/10">
                {{ __('Not detected') }}
            </span>
        @endif
    </div>
    <dl class="grid gap-px bg-brand-ink/10 sm:grid-cols-2 lg:grid-cols-4">
        <div class="bg-white px-5 py-4">
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
            <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $docker['version'] ?? __('Not detected') }}</dd>
        </div>
        <div class="bg-white px-5 py-4">
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Running containers') }}</dt>
            <dd class="mt-1 font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ number_format((int) ($docker['containers_running'] ?? 0)) }}</dd>
        </div>
        <div class="bg-white px-5 py-4">
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Stopped (exited)') }}</dt>
            <dd class="mt-1 font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ number_format((int) ($docker['containers_stopped'] ?? 0)) }}</dd>
        </div>
        <div class="bg-white px-5 py-4">
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Images') }}</dt>
            <dd class="mt-1 font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ number_format((int) ($docker['images_count'] ?? 0)) }}</dd>
        </div>
    </dl>
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 px-6 py-4 sm:px-7">
        @if ($checkedAt)
            <p class="text-xs text-brand-moss">
                {{ __('Last probed :time', ['time' => $checkedAt->diffForHumans()]) }}
            </p>
        @else
            <p class="text-xs text-brand-moss">{{ __('Not probed yet') }}</p>
        @endif
        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="refreshServerInventoryDetails"
                wire:loading.attr="disabled"
                wire:target="refreshServerInventoryDetails"
                class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                    {{ __('Refresh probe') }}
                </span>
                <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Refreshing…') }}
                </span>
            </button>
            @unless ($docker_present)
                @if ($installAction)
                    <button
                        type="button"
                        wire:click="confirmDockerInstall"
                        class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-forest/90"
                    >
                        <x-heroicon-o-cloud-arrow-down class="h-4 w-4" aria-hidden="true" />
                        {{ $installAction['label'] ?? __('Install Docker Engine') }}
                    </button>
                @endif
            @else
                @if ($upgradeAction)
                    <button
                        type="button"
                        wire:click="confirmDockerUpgrade"
                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-arrow-up-circle class="h-4 w-4" aria-hidden="true" />
                        {{ $upgradeAction['label'] ?? __('Upgrade Docker Engine') }}
                    </button>
                @endif
            @endunless
        </div>
    </div>
</section>

@unless ($docker_present)
    <p class="mt-4 text-sm text-brand-moss">
        {{ __('Install Docker Engine to browse containers, images, volumes, and compose projects from this workspace. The official get.docker.com script runs over SSH with sudo.') }}
    </p>
@endunless

<div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ([
        ['tab' => 'containers', 'label' => __('Containers'), 'desc' => __('Start, stop, logs, inspect, remove')],
        ['tab' => 'images', 'label' => __('Images'), 'desc' => __('Pull, list, remove, prune dangling')],
        ['tab' => 'volumes', 'label' => __('Volumes'), 'desc' => __('Named volume inventory')],
        ['tab' => 'networks', 'label' => __('Networks'), 'desc' => __('Bridge, host, and overlay networks')],
        ['tab' => 'compose', 'label' => __('Compose'), 'desc' => __('Projects from docker compose ls')],
        ['tab' => 'maintenance', 'label' => __('Maintenance'), 'desc' => __('Disk usage and prune tools')],
    ] as $card)
        <button
            type="button"
            wire:click="setWorkspaceTab('{{ $card['tab'] }}')"
            @disabled(! $docker_present)
            @class([
                'rounded-2xl border p-4 text-left shadow-sm transition',
                'border-brand-ink/10 bg-white hover:border-brand-gold/40 hover:bg-brand-cream/30' => $docker_present,
                'cursor-not-allowed border-brand-ink/8 bg-brand-sand/20 opacity-60' => ! $docker_present,
            ])
        >
            <p class="text-sm font-semibold text-brand-ink">{{ $card['label'] }}</p>
            <p class="mt-1 text-xs text-brand-moss">{{ $card['desc'] }}</p>
        </button>
    @endforeach
</div>

@if ($docker_present && ! $server->isDockerHost())
    <div class="mt-6 rounded-2xl border border-brand-gold/30 bg-brand-cream/40 p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Host sites in Docker') }}</p>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Create a site that deploys as a container on this VM. Dply publishes compose to a host port and routes traffic through the server webserver.') }}
                </p>
                @if (! ($canCreateDockerSite ?? false) && filled($siteCreateBlockedReason ?? ''))
                    <p class="mt-3 text-xs leading-relaxed text-amber-900">{{ $siteCreateBlockedReason }}</p>
                @endif
            </div>
            @if ($canCreateDockerSite ?? false)
                <a
                    href="{{ route('sites.create', $server) }}?deploy_stack=docker"
                    wire:navigate
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-forest/90"
                >
                    {{ __('Create Docker site') }}
                    <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" />
                </a>
            @else
                <span class="inline-flex shrink-0 cursor-not-allowed items-center gap-1.5 rounded-lg bg-brand-mist/30 px-3 py-2 text-xs font-semibold text-brand-moss">
                    <x-heroicon-o-no-symbol class="h-4 w-4" aria-hidden="true" />
                    {{ __('Create Docker site') }}
                </span>
            @endif
        </div>
    </div>
@endif
