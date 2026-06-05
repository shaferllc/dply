<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header :name="$title">
        <x-slot:icon>
            <x-dynamic-component :component="$icon" class="h-6 w-6 stroke-gray-400 dark:stroke-gray-600" />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand">
        @forelse ($servers as $server)
            <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">{{ $server->name }}</span>
                        @if ($server->stale)
                            <span class="text-[10px] uppercase tracking-wide text-red-500" title="No metrics in the last 10 minutes">stale</span>
                        @endif
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $server->service ?? $server->ip }}</div>
                </div>

                <div class="flex items-center gap-3 text-xs tabular-nums text-gray-600 dark:text-gray-400 shrink-0">
                    <span title="CPU">CPU {{ $server->cpu !== null ? round((float) $server->cpu).'%' : '—' }}</span>
                    <span title="Memory">MEM {{ $server->memory !== null ? round((float) $server->memory).'%' : '—' }}</span>
                    <span title="Disk">DISK {{ $server->disk !== null ? round((float) $server->disk).'%' : '—' }}</span>
                </div>
            </div>
        @empty
            <x-pulse::no-results />
        @endforelse
    </x-pulse::scroll>
</x-pulse::card>
