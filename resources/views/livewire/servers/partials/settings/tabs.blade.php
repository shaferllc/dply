<x-server-workspace-tablist :aria-label="__('Settings categories')">
    @foreach (($settingsTabs ?? config('server_settings.workspace_tabs', [])) as $slug => $meta)
        @php
            $tabIcon = ! empty($meta['icon']) ? 'heroicon-o-'.$meta['icon'] : null;
        @endphp
        <x-server-workspace-tab
            as="a"
            :id="'settings-tab-'.$slug"
            href="{{ route('servers.settings', ['server' => $server, 'section' => $slug]) }}"
            wire:navigate
            :active="$section === $slug"
            :icon="$tabIcon"
            :variant="$slug === 'danger' ? 'danger' : 'default'"
        >
            {{ __($meta['label']) }}
        </x-server-workspace-tab>
    @endforeach
</x-server-workspace-tablist>
