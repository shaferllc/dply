@php
    $installAction = is_array($serviceActions['install_docker'] ?? null) ? $serviceActions['install_docker'] : null;
    $upgradeAction = is_array($serviceActions['repair_docker'] ?? null) ? $serviceActions['repair_docker'] : null;
@endphp

<div class="min-w-0">
    <div class="border-b border-brand-ink/10">
        {{-- Dense head + stat strip, same as the other workspaces: the probe
             note rides the head, install state is the actions pill, and the
             four figures are a hairline strip instead of tall tiles. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-square-3-stack-3d"
            :title="__('Docker Engine')"
            :note="__('From the last inventory probe. Open Containers or Maintenance for live SSH data.')
                .' '.($checkedAt ? __('Last probed :time', ['time' => $checkedAt->diffForHumans()]) : __('Not probed yet'))"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                @if ($docker_present)
                    <span class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-full bg-emerald-50 px-2 text-[11px] font-semibold text-emerald-800 ring-1 ring-emerald-200">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        {{ __('Installed') }}
                    </span>
                @else
                    <span class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-full bg-white px-2 text-[11px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                        {{ __('Not detected') }}
                    </span>
                @endif

                <button
                    type="button"
                    wire:click="refreshServerInventoryDetails"
                    wire:loading.attr="disabled"
                    wire:target="refreshServerInventoryDetails"
                    title="{{ __('Refresh probe') }}"
                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md px-1.5 text-[11px] font-semibold text-brand-moss transition hover:bg-white hover:text-brand-ink hover:shadow-sm disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="refreshServerInventoryDetails" class="inline-flex">
                        <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    </span>
                    <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex">
                        <x-spinner variant="forest" size="sm" />
                    </span>
                    <span class="hidden sm:inline">{{ __('Refresh probe') }}</span>
                </button>

                @unless ($docker_present)
                    @if ($installAction)
                        <button
                            type="button"
                            wire:click="confirmDockerInstall"
                            class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md bg-brand-ink px-2 text-[11px] font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-m-cloud-arrow-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ $installAction['label'] ?? __('Install Docker Engine') }}
                        </button>
                    @endif
                @else
                    @if ($upgradeAction)
                        <button
                            type="button"
                            wire:click="confirmDockerUpgrade"
                            class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            <x-heroicon-m-arrow-up-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ $upgradeAction['label'] ?? __('Upgrade Docker Engine') }}
                        </button>
                    @endif
                @endunless
            </x-slot:actions>
        </x-workspace-panel-head>

        <x-workspace-stat-strip class="border-b border-brand-ink/10" :stats="[
            [
                'label' => __('Version'),
                'value' => $docker['version'] ?? __('Not detected'),
                'hint' => __('Docker Engine version from the last probe'),
            ],
            [
                'label' => __('Running containers'),
                'value' => number_format((int) ($docker['containers_running'] ?? 0)),
                'tone' => ((int) ($docker['containers_running'] ?? 0)) > 0 ? 'ok' : null,
                'hint' => __('Containers currently running'),
            ],
            [
                'label' => __('Stopped (exited)'),
                'value' => number_format((int) ($docker['containers_stopped'] ?? 0)),
                'tone' => ((int) ($docker['containers_stopped'] ?? 0)) > 0 ? 'warn' : null,
                'hint' => __('Containers in the exited state'),
            ],
            [
                'label' => __('Images'),
                'value' => number_format((int) ($docker['images_count'] ?? 0)),
                'hint' => __('Images stored on this server'),
            ],
        ]" />
    </div>

    @unless ($docker_present)
        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-brand-ink/10 px-4 py-2 text-[11px] text-brand-moss sm:px-5">
            <x-heroicon-m-information-circle class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('Install Docker Engine to browse containers, images, volumes, and compose projects from this workspace. The official get.docker.com script runs over SSH with sudo.') }}
        </p>
    @endunless

    <div class="grid gap-2 border-b border-brand-ink/10 px-4 py-3.5 sm:grid-cols-2 sm:px-5 lg:grid-cols-3">
        @foreach ([
            ['tab' => 'containers', 'label' => __('Containers'), 'desc' => __('Start, stop, logs, inspect, remove')],
            ['tab' => 'images', 'label' => __('Images'), 'desc' => __('Pull, list, remove, prune dangling')],
            ['tab' => 'volumes', 'label' => __('Volumes'), 'desc' => __('Named volume inventory')],
            ['tab' => 'networks', 'label' => __('Networks'), 'desc' => __('Bridge, host, and overlay networks')],
            ['tab' => 'compose', 'label' => __('Compose'), 'desc' => __('Projects from docker compose ls')],
            ['tab' => 'maintenance', 'label' => __('Maintenance'), 'desc' => __('Disk usage and prune tools')],
        ] as $tile)
            <button
                type="button"
                wire:click="setWorkspaceTab('{{ $tile['tab'] }}')"
                @disabled(! $docker_present)
                @class([
                    'group flex items-start gap-3 rounded-xl border p-3 text-left transition',
                    'border-brand-ink/10 bg-brand-sand/15 hover:border-brand-forest/30 hover:bg-brand-sand/30' => $docker_present,
                    'cursor-not-allowed border-brand-ink/8 bg-brand-sand/20 opacity-60' => ! $docker_present,
                ])
            >
                <span class="min-w-0">
                    <span @class([
                        'block text-sm font-semibold',
                        'text-brand-ink group-hover:text-brand-forest' => $docker_present,
                        'text-brand-ink' => ! $docker_present,
                    ])>{{ $tile['label'] }}</span>
                    <span class="mt-0.5 block text-[12px] leading-5 text-brand-moss">{{ $tile['desc'] }}</span>
                </span>
            </button>
        @endforeach
    </div>

    @if ($docker_present && ! $server->isDockerHost())
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-4 py-2.5 sm:px-5">
            <div class="min-w-0">
                <p class="text-xs font-semibold text-brand-ink">{{ __('Host sites in Docker') }}</p>
                <p class="mt-0.5 max-w-2xl text-[11px] leading-relaxed text-brand-moss">
                    {{ __('Create a site that deploys as a container on this VM. Dply publishes compose to a host port and routes traffic through the server webserver.') }}
                </p>
                @if (! ($canCreateDockerSite ?? false) && filled($siteCreateBlockedReason ?? ''))
                    <p class="mt-1.5 text-[11px] leading-relaxed text-amber-900">{{ $siteCreateBlockedReason }}</p>
                @endif
            </div>
            @if ($canCreateDockerSite ?? false)
                <a
                    href="{{ route('sites.create', $server) }}?deploy_stack=docker"
                    wire:navigate
                    class="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 text-[11px] font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                >
                    {{ __('Create Docker site') }}
                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </a>
            @else
                <span class="inline-flex h-7 shrink-0 cursor-not-allowed items-center gap-1.5 rounded-lg bg-brand-mist/30 px-2.5 text-[11px] font-semibold text-brand-moss">
                    <x-heroicon-m-no-symbol class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Create Docker site') }}
                </span>
            @endif
        </div>
    @endif
</div>
