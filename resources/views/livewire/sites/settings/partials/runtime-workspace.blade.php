@php
    $hasLanguageTabs = count($runtimeTabs) > 1;
@endphp

@if ($hasLanguageTabs)
    <x-server-workspace-tablist :aria-label="__('Runtime sections')">
        @foreach ($runtimeTabs as $tabKey => $tabLabel)
            <x-server-workspace-tab
                as="a"
                id="runtime-tab-{{ $tabKey }}"
                :active="$runtimeTab === $tabKey"
                :icon="$runtimeTabIcons[$tabKey] ?? 'heroicon-o-cube-transparent'"
                href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime', 'tab' => $tabKey]) }}"
                wire:navigate
            >{{ $tabLabel }}</x-server-workspace-tab>
        @endforeach
    </x-server-workspace-tablist>
@endif

@if ($runtimeTab === 'overview')
    @include('livewire.sites.settings.partials.runtime')
@elseif ($runtimeTab === 'php')
    @include('livewire.sites.settings.partials.runtime.php')
@elseif ($runtimeTab === 'ruby')
    @include('livewire.sites.settings.partials.runtime.ruby')
@elseif ($runtimeTab === 'static')
    @include('livewire.sites.settings.partials.runtime.static')
@endif
