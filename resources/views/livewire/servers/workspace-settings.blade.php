@php
    $card = 'border-b border-brand-ink/10';
    $settingsDescription = __('Navigate through the tabs to manage different settings categories. Changes in each section are automatically saved.');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="settings"
    :title="__('Settings')"
    :description="$settingsDescription"
    hide-hero
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-cog-8-tooth class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Settings') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ $settingsDescription }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
            @include('livewire.servers.partials.settings.tabs', [
                'server' => $server,
                'section' => $section,
                'settingsTabs' => $settingsTabs,
            ])
        </div>

        <div class="min-w-0">
            @include('livewire.servers.partials.settings-tab', [
                'workspaces' => $workspaces,
                'card' => $card,
                'section' => $section,
                'costReport' => $costReport ?? null,
            ])
        </div>
    </section>

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
