<div class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-cube"
        :title="__('Containers')"
        :count="is_array($containers) && $containers !== []
            ? trans_choice('{1} :count container|[2,*] :count containers', count($containers), ['count' => count($containers)])
            : null"
        :note="__('Shell opens a rolling command session (docker exec over SSH). Exec runs a single confirmed command. When Run workspace is enabled, Run opens the library with container scope for longer scripts.')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <button
                type="button"
                wire:click="loadContainers"
                wire:loading.attr="disabled"
                wire:target="loadContainers"
                title="{{ __('Refresh') }}"
                class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md px-1.5 text-xs font-semibold text-brand-moss transition hover:bg-white hover:text-brand-ink hover:shadow-sm disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="loadContainers" class="inline-flex">
                    <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </span>
                <span wire:loading wire:target="loadContainers" class="inline-flex">
                    <x-spinner variant="forest" size="sm" />
                </span>
                <span class="hidden sm:inline">{{ __('Refresh') }}</span>
            </button>
        </x-slot:actions>
    </x-workspace-panel-head>

    @if ($containersLoading && $containers === null)
        {{-- Stub the table we're about to paint rather than centring a spinner
             in an empty band. --}}
        @php $bar = 'animate-pulse rounded bg-brand-ink/10'; @endphp
        <div class="px-4 py-3.5 sm:px-5" aria-busy="true" aria-live="polite">
            <span class="sr-only">{{ __('Loading containers…') }}</span>
            <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                <div class="flex items-center gap-3 bg-brand-sand/30 px-3 py-2">
                    @foreach ([22, 20, 16, 14, 18] as $width)
                        <span class="h-2 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                    @endforeach
                </div>
                <div class="divide-y divide-brand-ink/10 bg-white">
                    @foreach (range(1, 4) as $row)
                        <div class="flex items-center gap-3 px-3 py-2">
                            @foreach ([22, 20, 16, 14, 18] as $width)
                                <span class="h-2.5 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @elseif ($containersError)
        {{-- The probe failure is a state, not loose red prose — frame it like
             every other error strip in the workspace. --}}
        <div class="px-4 py-3.5 sm:px-5">
            <div class="flex flex-wrap items-center gap-3 rounded-xl border border-rose-200 bg-rose-50/70 px-3 py-2.5" role="alert">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-rose-700 ring-1 ring-rose-200">
                    <x-heroicon-m-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1 basis-64">
                    <p class="text-xs font-semibold text-rose-900">{{ __('Could not list containers') }}</p>
                    <p class="mt-0.5 break-words text-xs leading-relaxed text-rose-800">{{ $containersError }}</p>
                </div>
                <button
                    type="button"
                    wire:click="setWorkspaceTab('overview')"
                    class="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-2.5 text-xs font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50"
                >
                    {{ __('Open Overview') }}
                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </button>
            </div>
        </div>
    @elseif ($containers === [] || $containers === null)
        <div class="px-4 py-3.5 sm:px-5">
            <x-empty-state
                compact
                icon="heroicon-o-cube"
                :title="__('No containers reported')"
                :description="__('Nothing is running or stopped on this engine yet. Start a container over SSH, or deploy a Docker site to this server.')"
            />
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-3 py-2 sm:px-5">{{ __('Name') }}</th>
                        <th class="px-3 py-2">{{ __('Image') }}</th>
                        <th class="px-3 py-2">{{ __('Status') }}</th>
                        <th class="px-3 py-2">{{ __('Ports') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($containers as $row)
                        @php
                            $running = str_contains(strtolower($row['state'] ?? ''), 'running')
                                || str_contains(strtolower($row['status'] ?? ''), 'up ');
                            $ref = $row['id'];
                            $name = $row['name'];
                            $imageRef = $row['image'];
                            $linkedSite = \App\Support\Servers\DockerManagedSiteIndex::siteForContainer($row, $managedSites);
                        @endphp
                        <tr wire:key="docker-container-{{ $ref }}">
                            <td class="px-3 py-2 sm:px-5">
                                <div class="font-mono text-xs text-brand-ink">{{ $name }}</div>
                                @if ($linkedSite)
                                    <a href="{{ $linkedSite['url'] }}" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:underline">
                                        <x-heroicon-o-globe-alt class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        {{ __('Site: :name', ['name' => $linkedSite['name']]) }}
                                    </a>
                                @endif
                            </td>
                            <td class="max-w-[10rem] truncate px-3 py-2 font-mono text-xs text-brand-moss" title="{{ $imageRef }}">{{ $imageRef }}</td>
                            <td class="px-3 py-2 text-brand-moss">{{ $row['status'] }}</td>
                            <td class="max-w-[8rem] truncate px-3 py-2 font-mono text-xs text-brand-moss" title="{{ $row['ports'] ?? '' }}">{{ $row['ports'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                <div class="inline-flex flex-wrap justify-end gap-1.5">
                                    <button type="button" wire:click="openContainerShell(@js($ref), @js($name))" @disabled(! $running) class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50">{{ __('Shell') }}</button>
                                    @feature('workspace.run')
                                        @if ($running)
                                            <a href="{{ route('servers.run', ['server' => $server, 'container' => $ref, 'container_name' => $name]) }}" wire:navigate class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-forest transition hover:bg-brand-sand/40">{{ __('Run') }}</a>
                                        @endif
                                    @endfeature
                                    <button type="button" wire:click="openContainerExec(@js($ref), @js($name))" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">{{ __('Exec') }}</button>
                                    @can('update', $server)
                                        <button type="button" wire:click="openContainerLogs(@js($ref), @js($name))" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">{{ __('Logs') }}</button>
                                        <button type="button" wire:click="openContainerInspect(@js($ref), @js($name))" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">{{ __('Inspect') }}</button>
                                    @endcan
                                    @if (! $running)
                                        <button type="button" wire:click="confirmDockerContainerAction('docker_container_start', @js($ref))" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">{{ __('Start') }}</button>
                                    @else
                                        <button type="button" wire:click="confirmDockerContainerAction('docker_container_stop', @js($ref))" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">{{ __('Stop') }}</button>
                                        <button type="button" wire:click="confirmDockerContainerAction('docker_container_restart', @js($ref))" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">{{ __('Restart') }}</button>
                                    @endif
                                    <button type="button" wire:click="confirmDockerContainerAction('docker_container_rm', @js($ref))" class="inline-flex h-6 items-center rounded-md border border-rose-200 bg-rose-50 px-2 text-xs font-semibold text-rose-800 transition hover:bg-rose-100">{{ __('Remove') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
