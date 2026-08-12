{{--
    Lazy skeleton for the Settings card (see SettingsCard::placeholder). Mirrors
    settings-card.blade.php: same panel head, the real category strip with the
    requested section highlighted, and a body skeleton shaped for that section.

    The strip is real links here, not the card's wire:click buttons: this renders
    before the component has hydrated, so there's nothing yet to receive an
    action. Clicking during the skeleton therefore navigates — which is the same
    destination, just the slower route.

    Receives: $tabs (slug => meta), $skeletonSection (slug).

    Named $skeletonSection rather than $section on purpose: Livewire regenerates
    this view with the component's public properties, so a `section` key would be
    overwritten by the property's default and every section would render the
    Connection skeleton. See SettingsCard::placeholder().
--}}
@php
    $settingsDescription = __('Navigate through the tabs to manage different settings categories. Unsaved edits surface a save bar at the bottom of the page.');
@endphp

<div class="min-w-0">
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading settings…') }}</span>

        <x-workspace-panel-head
            dense
            icon="heroicon-o-cog-8-tooth"
            :title="__('Settings')"
            :note="$settingsDescription"
            class="border-b border-brand-ink/10"
        />

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Settings categories')" scroll bare class="!mb-0 w-full">
                @foreach ($tabs as $slug => $meta)
                    <x-server-workspace-tab
                        as="a"
                        wire:key="settings-tab-{{ $slug }}"
                        :id="'settings-tab-'.$slug"
                        :href="route('servers.settings', $slug === 'connection' ? ['server' => $server] : ['server' => $server, 'tab' => $slug])"
                        wire:navigate
                        :active="$skeletonSection === $slug"
                        :icon="! empty($meta['icon']) ? 'heroicon-o-'.$meta['icon'] : null"
                        :variant="$slug === 'danger' ? 'danger' : 'default'"
                    >
                        {{ __($meta['label']) }}
                    </x-server-workspace-tab>
                @endforeach
            </x-server-workspace-tablist>
        </div>

        @include('livewire.servers.partials.settings-section-skeleton', ['section' => $skeletonSection])
    </section>
</div>
