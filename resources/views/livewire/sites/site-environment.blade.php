{{-- Standalone Environment page (out of the Deployments hub). Same workspace
     chrome (sidebar + header) as the other site sections; renders the env editor
     partial in the main panel. --}}
<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail
        :items="$settingsBreadcrumbs"
        doc-contextual
        :contextual-doc-slug="$contextualDocSlug ?? null"
        class="mb-6"
    />

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <x-hero-card
                :eyebrow="$workspaceTitle"
                :title="__('Environment')"
                :description="__('Manage the environment variables and secrets used by this site at runtime.')"
                icon="key"
            />

            @if ($headerRoleLabel !== null)
                <div class="mt-3 flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset {{ $headerRoleTone }}"
                          title="{{ __('Your access level for this :resource', ['resource' => strtolower($resourceNoun)]) }}">
                        @if ($headerIsDeployer)
                            <x-heroicon-m-rocket-launch class="h-3 w-3" aria-hidden="true" />
                        @elseif ($headerCanUpdateSite)
                            <x-heroicon-m-pencil-square class="h-3 w-3" aria-hidden="true" />
                        @else
                            <x-heroicon-m-eye class="h-3 w-3" aria-hidden="true" />
                        @endif
                        {{ $headerRoleLabel }}
                    </span>
                </div>
            @endif

            <main class="min-w-0 space-y-6 mt-8">
                @if ($watchedConsoleRunId)
                    <div wire:poll.3s="resolveWatchedConsoleAction" class="hidden" aria-hidden="true"></div>
                @endif

                @if ($sectionConsoleActionKinds !== [])
                    <div
                        id="site-console-action-banner"
                        x-data="{}"
                        x-on:dply-console-action-focus.window="$nextTick(() => {
                            const el = document.getElementById('site-console-action-banner');
                            if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                        })"
                    >
                        @include('livewire.partials.console-action-banner-static', [
                            'run' => $sectionConsoleActionRun,
                            'kindLabels' => (array) config('console_actions.kinds', []),
                        ])
                    </div>
                @endif

                @include('livewire.sites.settings.partials.environment')
            </main>
        </div>
    </div>

    {{-- Required by the env partial's confirm-driven actions (Remove variable,
         Sync from server, …): without it, clicking Remove flips the confirm
         state but no dialog renders, so the removal never gets confirmed. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
