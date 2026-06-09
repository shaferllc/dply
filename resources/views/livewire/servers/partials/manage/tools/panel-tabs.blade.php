<x-server-workspace-tablist :aria-label="__('Tools sections')" class="mb-1">
    <x-server-workspace-tab
        id="manage-tools-tab-catalog"
        :active="$toolsPanel === 'tools'"
        wire:click="setToolsPanel('tools')"
        icon="heroicon-o-wrench-screwdriver"
    >
        {{ __('Tools') }}
    </x-server-workspace-tab>
    <x-server-workspace-tab
        id="manage-tools-tab-runtimes"
        :active="$toolsPanel === 'runtimes'"
        wire:click="setToolsPanel('runtimes')"
        icon="heroicon-o-cpu-chip"
    >
        {{ __('Runtimes') }}
        @if (($summary['runtime_versions'] ?? 0) > 0)
            <span class="ml-1 rounded-full bg-brand-sand/60 px-1.5 py-0.5 font-mono text-[10px] tabular-nums text-brand-moss">
                {{ $summary['runtime_versions'] }}
            </span>
        @endif
    </x-server-workspace-tab>
</x-server-workspace-tablist>
