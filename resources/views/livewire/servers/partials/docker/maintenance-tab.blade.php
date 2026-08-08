<div class="border-b border-brand-ink/10">
    @include('livewire.servers.partials.docker._list-head', [
        'icon' => 'heroicon-o-chart-pie',
        'title' => __('Disk usage'),
        'target' => 'loadSystemDiskUsage',
        'rows' => $systemDf,
        'note' => __('Reported by docker system df — image, container, volume, and cache space.'),
    ])

    @if ($systemDfLoading || $systemDfError || $systemDf === [] || $systemDf === null)
        @include('livewire.servers.partials.docker._list-state', [
            'loading' => $systemDfLoading,
            'error' => $systemDfError,
            'rows' => $systemDf,
            'icon' => 'heroicon-o-chart-pie',
            'errorTitle' => __('Could not read disk usage'),
            'emptyTitle' => __('No disk usage data yet'),
            'emptyDescription' => __('Nothing returned from docker system df. Refresh to probe the engine for image, container, and volume reclaimable space.'),
            'columns' => [20, 12, 12, 18, 18],
        ])
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-3 py-2 sm:px-5">{{ __('Type') }}</th>
                        <th class="px-3 py-2">{{ __('Total') }}</th>
                        <th class="px-3 py-2">{{ __('Active') }}</th>
                        <th class="px-3 py-2">{{ __('Size') }}</th>
                        <th class="px-3 py-2">{{ __('Reclaimable') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($systemDf as $row)
                        <tr wire:key="docker-df-{{ $row['type'] }}">
                            <td class="px-3 py-2 font-medium text-brand-ink sm:px-5">{{ $row['type'] }}</td>
                            <td class="px-3 py-2 tabular-nums text-brand-moss">{{ $row['total'] }}</td>
                            <td class="px-3 py-2 tabular-nums text-brand-moss">{{ $row['active'] }}</td>
                            <td class="px-3 py-2 text-brand-moss">{{ $row['size'] }}</td>
                            <td class="px-3 py-2 text-brand-moss">{{ $row['reclaimable'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        tone="amber"
        icon="heroicon-o-trash"
        :title="__('Prune & cleanup')"
        :note="__('Destructive actions run over SSH and stream output in the banner above.')"
        class="border-b border-brand-ink/10"
    />
    <div class="flex flex-wrap gap-2 px-4 py-3.5 sm:px-5">
        @if (is_array($serviceActions['docker_image_prune'] ?? null))
            <button type="button" wire:click="confirmDockerImagePrune" class="inline-flex h-7 items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 text-[11px] font-semibold text-amber-900 shadow-sm transition hover:bg-amber-100">
                <x-heroicon-m-sparkles class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ $serviceActions['docker_image_prune']['label'] }}
            </button>
        @endif
        @if (is_array($serviceActions['docker_volume_prune'] ?? null))
            <button type="button" wire:click="confirmDockerVolumePrune" class="inline-flex h-7 items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 text-[11px] font-semibold text-amber-900 shadow-sm transition hover:bg-amber-100">
                <x-heroicon-m-sparkles class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ $serviceActions['docker_volume_prune']['label'] }}
            </button>
        @endif
        @if (is_array($serviceActions['docker_system_prune'] ?? null))
            <button type="button" wire:click="confirmDockerSystemPrune" class="inline-flex h-7 items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-2.5 text-[11px] font-semibold text-rose-800 shadow-sm transition hover:bg-rose-100">
                <x-heroicon-m-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ $serviceActions['docker_system_prune']['label'] }}
            </button>
        @endif
    </div>
</div>
