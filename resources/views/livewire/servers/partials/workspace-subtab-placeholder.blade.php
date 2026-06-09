{{--
    Lazy-load skeleton for workspace tabs that have their own URL-navigated
    sub-tab strip (Settings, Manage). Keeps the chrome AND the sub-tab strip
    visible — with the destination sub-tab highlighted — while only the
    content area below skeletons, then hydrates.

    Receives: $server, $active, $title, $tabs (slug => meta), $section,
    $routeName, $idPrefix, $ariaLabel.
--}}
<x-server-workspace-layout :server="$server" :active="$active" :title="$title">
    <div class="space-y-6">
        <x-server-workspace-tablist :aria-label="$ariaLabel">
            @foreach ($tabs as $slug => $meta)
                <x-server-workspace-tab
                    as="a"
                    :id="$idPrefix.$slug"
                    :href="route($routeName, ['server' => $server, 'section' => $slug])"
                    wire:navigate
                    :active="$section === $slug"
                    :icon="! empty($meta['icon']) ? 'heroicon-o-'.$meta['icon'] : null"
                    :variant="$slug === 'danger' ? 'danger' : 'default'"
                >
                    {{ __($meta['label']) }}
                </x-server-workspace-tab>
            @endforeach
        </x-server-workspace-tablist>

        @include('livewire.servers.partials._skeleton-cards')
    </div>
</x-server-workspace-layout>
