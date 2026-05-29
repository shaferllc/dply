<x-server-workspace-layout
    :server="$server"
    active="docker"
    :title="__('Docker')"
    :description="__('Docker Engine on this server — version summary from inventory probe, live container and image lists over SSH.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($manageRemoteTaskId)
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif

    <x-explainer>
        <p>{{ __('Overview counts come from the last server inventory probe. Container and image tables load on demand over SSH when you open those tabs.') }}</p>
    </x-explainer>

    @if ($opsReady && ! $isDeployer)
        <x-server-workspace-tablist :aria-label="__('Docker workspace sections')" class="mb-6">
            <x-server-workspace-tab
                id="docker-tab-overview"
                :active="$workspace_tab === 'overview'"
                wire:click="setWorkspaceTab('overview')"
                icon="heroicon-o-square-3-stack-3d"
            >
                {{ __('Overview') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="docker-tab-containers"
                :active="$workspace_tab === 'containers'"
                wire:click="setWorkspaceTab('containers')"
                icon="heroicon-o-cube"
            >
                {{ __('Containers') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="docker-tab-images"
                :active="$workspace_tab === 'images'"
                wire:click="setWorkspaceTab('images')"
                icon="heroicon-o-photo"
            >
                {{ __('Images') }}
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

        @if ($workspace_tab === 'overview')
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Engine') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('From the last inventory probe.') }}</p>
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
                @if ($checkedAt)
                    <p class="border-t border-brand-ink/10 px-6 py-3 text-xs text-brand-moss sm:px-7">
                        {{ __('Last probed :time', ['time' => $checkedAt->diffForHumans()]) }}
                    </p>
                @endif
            </section>

            @unless ($docker_present)
                <p class="mt-4 text-sm text-brand-moss">
                    {{ __('Docker was not detected on the last probe. Install it from Manage → Tools, then refresh inventory.') }}
                    <a href="{{ route('servers.manage', ['server' => $server, 'section' => 'tools']) }}" wire:navigate class="font-semibold text-brand-ink underline decoration-brand-gold/60 underline-offset-4">{{ __('Open Tools') }}</a>
                </p>
            @endunless
        @endif

        @if ($workspace_tab === 'containers')
            <section class="dply-card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Containers') }}</h2>
                    <button
                        type="button"
                        wire:click="loadContainers"
                        wire:loading.attr="disabled"
                        wire:target="loadContainers"
                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="loadContainers" class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Refresh') }}
                        </span>
                        <span wire:loading wire:target="loadContainers" class="inline-flex items-center gap-1.5">
                            <x-spinner variant="forest" size="sm" />
                            {{ __('Refreshing…') }}
                        </span>
                    </button>
                </div>

                @if ($containersLoading && $containers === null)
                    <div class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Loading containers…') }}
                    </div>
                @elseif ($containersError)
                    <p class="px-6 py-8 text-sm text-rose-700 sm:px-7">{{ $containersError }}</p>
                @elseif ($containers === [] || $containers === null)
                    <p class="px-6 py-8 text-sm text-brand-moss sm:px-7">{{ __('No containers reported.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                                <tr>
                                    <th class="px-4 py-3 sm:px-6">{{ __('Name') }}</th>
                                    <th class="px-4 py-3">{{ __('Image') }}</th>
                                    <th class="px-4 py-3">{{ __('Status') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10 bg-white">
                                @foreach ($containers as $row)
                                    @php
                                        $running = str_contains(strtolower($row['state'] ?? ''), 'running')
                                            || str_contains(strtolower($row['status'] ?? ''), 'up ');
                                        $ref = $row['id'];
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-3 font-mono text-xs text-brand-ink sm:px-6">{{ $row['name'] }}</td>
                                        <td class="max-w-[12rem] truncate px-4 py-3 font-mono text-xs text-brand-moss" title="{{ $row['image'] }}">{{ $row['image'] }}</td>
                                        <td class="px-4 py-3 text-brand-moss">{{ $row['status'] }}</td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="inline-flex flex-wrap justify-end gap-2">
                                                @if (! $running)
                                                    <button
                                                        type="button"
                                                        wire:click="confirmDockerContainerAction('docker_container_start', @js($ref))"
                                                        class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                                    >
                                                        {{ __('Start') }}
                                                    </button>
                                                @else
                                                    <button
                                                        type="button"
                                                        wire:click="confirmDockerContainerAction('docker_container_stop', @js($ref))"
                                                        class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                                    >
                                                        {{ __('Stop') }}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="confirmDockerContainerAction('docker_container_restart', @js($ref))"
                                                        class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                                    >
                                                        {{ __('Restart') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        @if ($workspace_tab === 'images')
            <section class="dply-card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Images') }}</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($pruneAction)
                            <button
                                type="button"
                                wire:click="confirmDockerImagePrune"
                                class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-100"
                            >
                                {{ $pruneAction['label'] }}
                            </button>
                        @endif
                        <button
                            type="button"
                            wire:click="loadImages"
                            wire:loading.attr="disabled"
                            wire:target="loadImages"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="loadImages" class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Refresh') }}
                            </span>
                            <span wire:loading wire:target="loadImages" class="inline-flex items-center gap-1.5">
                                <x-spinner variant="forest" size="sm" />
                                {{ __('Refreshing…') }}
                            </span>
                        </button>
                    </div>
                </div>

                @if ($imagesLoading && $images === null)
                    <div class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Loading images…') }}
                    </div>
                @elseif ($imagesError)
                    <p class="px-6 py-8 text-sm text-rose-700 sm:px-7">{{ $imagesError }}</p>
                @elseif ($images === [] || $images === null)
                    <p class="px-6 py-8 text-sm text-brand-moss sm:px-7">{{ __('No images reported.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                                <tr>
                                    <th class="px-4 py-3 sm:px-6">{{ __('Repository') }}</th>
                                    <th class="px-4 py-3">{{ __('Tag') }}</th>
                                    <th class="px-4 py-3">{{ __('Size') }}</th>
                                    <th class="px-4 py-3">{{ __('Created') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10 bg-white">
                                @foreach ($images as $row)
                                    <tr>
                                        <td class="px-4 py-3 font-mono text-xs text-brand-ink sm:px-6">{{ $row['repository'] }}</td>
                                        <td class="px-4 py-3 font-mono text-xs text-brand-moss">{{ $row['tag'] }}</td>
                                        <td class="px-4 py-3 text-brand-moss">{{ $row['size'] }}</td>
                                        <td class="px-4 py-3 text-brand-moss">{{ $row['created'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    @elseif ($isDeployer)
        <p class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 px-5 py-4 text-sm text-brand-moss">
            {{ __('Deployers have read-only access to this workspace.') }}
        </p>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif
</x-server-workspace-layout>
