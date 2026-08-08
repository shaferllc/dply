<div class="border-b border-brand-ink/10">
    @include('livewire.servers.partials.docker._list-head', [
        'icon' => 'heroicon-o-circle-stack',
        'title' => __('Volumes'),
        'target' => 'loadVolumes',
        'rows' => $volumes,
        'note' => __('Named volume inventory for this engine. Use Maintenance → Prune unused volumes to reclaim space from volumes not referenced by a container.'),
    ])

    @if ($volumesLoading || $volumesError || $volumes === [] || $volumes === null)
        @include('livewire.servers.partials.docker._list-state', [
            'loading' => $volumesLoading,
            'error' => $volumesError,
            'rows' => $volumes,
            'icon' => 'heroicon-o-circle-stack',
            'errorTitle' => __('Could not list volumes'),
            'emptyTitle' => __('No named volumes'),
            'emptyDescription' => __('Docker reports no named volumes on this engine. Volumes appear here once a container or compose project creates one.'),
            'columns' => [24, 18, 20, 14],
        ])
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-3 py-2 sm:px-5">{{ __('Name') }}</th>
                        <th class="px-3 py-2">{{ __('Driver') }}</th>
                        <th class="px-3 py-2">{{ __('Scope') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($volumes as $row)
                        <tr wire:key="docker-volume-{{ $row['name'] }}">
                            <td class="px-3 py-2 font-mono text-xs text-brand-ink sm:px-5">{{ $row['name'] }}</td>
                            <td class="px-3 py-2 text-brand-moss">{{ $row['driver'] }}</td>
                            <td class="px-3 py-2 text-brand-moss">{{ $row['scope'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
