@php
    $hasLanguageTabs = count($runtimeTabs) > 1;
@endphp

@if ($hasLanguageTabs)
    <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
        <x-server-workspace-tablist
            :aria-label="__('Runtime sections')"
            scroll
            class="!mb-0 w-full border-0 bg-transparent p-0 shadow-none"
        >
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
    </div>
@endif

<div class="min-w-0">
@if ($runtimeTab === 'overview')
    @include('livewire.sites.settings.partials.runtime')
@elseif ($runtimeTab === 'php')
    @include('livewire.sites.settings.partials.runtime.php')
@elseif ($runtimeTab === 'ruby')
    @include('livewire.sites.settings.partials.runtime.ruby')
@elseif ($runtimeTab === 'static')
    @include('livewire.sites.settings.partials.runtime.static')
@endif
</div>
