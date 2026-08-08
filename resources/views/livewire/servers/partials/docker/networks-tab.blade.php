<div class="border-b border-brand-ink/10">
    @include('livewire.servers.partials.docker._list-head', [
        'icon' => 'heroicon-o-globe-alt',
        'title' => __('Networks'),
        'target' => 'loadNetworks',
        'rows' => $networks,
        'note' => __('Bridge, host, and overlay networks defined on this engine.'),
    ])

    @if ($networksLoading || $networksError || $networks === [] || $networks === null)
        @include('livewire.servers.partials.docker._list-state', [
            'loading' => $networksLoading,
            'error' => $networksError,
            'rows' => $networks,
            'icon' => 'heroicon-o-globe-alt',
            'errorTitle' => __('Could not list networks'),
            'emptyTitle' => __('No networks reported'),
            'emptyDescription' => __('Docker normally ships bridge, host, and none networks. An empty list usually means the engine is not reachable.'),
            'columns' => [24, 16, 16, 14],
        ])
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-3 py-2 sm:px-5">{{ __('Name') }}</th>
                        <th class="px-3 py-2">{{ __('ID') }}</th>
                        <th class="px-3 py-2">{{ __('Driver') }}</th>
                        <th class="px-3 py-2">{{ __('Scope') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($networks as $row)
                        <tr wire:key="docker-network-{{ $row['id'] }}">
                            <td class="px-3 py-2 font-mono text-xs text-brand-ink sm:px-5">{{ $row['name'] }}</td>
                            <td class="px-3 py-2 font-mono text-[11px] text-brand-moss">{{ $row['id'] }}</td>
                            <td class="px-3 py-2 text-brand-moss">{{ $row['driver'] }}</td>
                            <td class="px-3 py-2 text-brand-moss">{{ $row['scope'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
