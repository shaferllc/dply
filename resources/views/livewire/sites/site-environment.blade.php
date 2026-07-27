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
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Environment') }}</h2>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Manage the environment variables and secrets used by this site at runtime.') }}
                                </p>
                            </div>
                        </div>
                        @if ($headerRoleLabel !== null)
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
                        @endif
                    </div>
                </div>

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
