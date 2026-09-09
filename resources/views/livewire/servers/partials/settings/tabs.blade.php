<x-server-workspace-tablist :aria-label="__('Settings categories')" scroll bare class="!mb-0 w-full">
    @foreach (($settingsTabs ?? config('server_settings.workspace_tabs', [])) as $slug => $meta)
        @php
            $tabIcon = ! empty($meta['icon']) ? 'heroicon-o-'.$meta['icon'] : null;
        @endphp
        {{-- In-place switch on the SettingsCard component. This is only safe
             because the strip now lives inside that card: the update renders the
             card, not the whole page, so it's short enough that a second click
             isn't colliding with a page-sized request. #[Url] on the card keeps
             ?tab= in the address bar, so deep links still work. --}}
        <x-server-workspace-tab
            wire:key="settings-tab-{{ $slug }}"
            :id="'settings-tab-'.$slug"
            wire:click="setSection('{{ $slug }}')"
            :active="$section === $slug"
            :icon="$tabIcon"
            :variant="$slug === 'danger' ? 'danger' : 'default'"
        >
            {{ __($meta['label']) }}
        </x-server-workspace-tab>
    @endforeach
</x-server-workspace-tablist>
