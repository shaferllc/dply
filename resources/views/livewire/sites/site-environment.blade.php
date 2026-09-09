{{-- Standalone Environment page — merged chrome (no floating hero). --}}
<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail
        :items="$settingsBreadcrumbs"
        :site="$site"
        doc-contextual
        :contextual-doc-slug="$contextualDocSlug ?? null"
        class="mb-6"
    />

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-key"
                    :title="__('Environment')"
                    :note="__('Manage the environment variables and secrets used by this site at runtime.')"
                >
                    <x-slot:actions>
                        @include('livewire.sites.partials.header-role-badge')
                    </x-slot:actions>
                </x-workspace-panel-head>

                <main class="min-w-0">
                    @if ($watchedConsoleRunId)
                        <div wire:poll.3s="resolveWatchedConsoleAction" class="hidden" aria-hidden="true"></div>
                    @endif

                    {{-- Console-run banner renders inside the env partial's
                         consolidated "Needs attention" strip (consoleRunInline). --}}
                    @include('livewire.sites.settings.partials.environment', [
                        'consoleRunInline' => true,
                        'envMergedChrome' => true,
                    ])
                </main>
            </section>
        </div>
    </div>

    {{-- Required by the env partial's confirm-driven actions (Remove variable,
         Sync from server, …): without it, clicking Remove flips the confirm
         state but no dialog renders, so the removal never gets confirmed. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
