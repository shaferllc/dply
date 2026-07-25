<div
    wire:key="servers-epoch-{{ $serverListEpoch }}"
    @if ($needsPoll)
        wire:poll.10s
    @endif
>
    <x-servers-index-page
        :grouped-rows="$groupedRows"
        :summary="$summary"
        :has-servers-in-scope="$hasServersInScope"
        :status-options="$statusOptions"
        :sort-options="$sortOptions"
        :tag-options="$tagOptions"
        :view-mode="$viewMode"
        :show-fleet-ops="false"
        :show-mutations="true"
        :show-hero-actions="true"
        :eyebrow="__('Fleet')"
        :breadcrumbs="array_values(array_filter([
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            multi_surface_active()
                ? ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group']
                : null,
            ['label' => __('Servers'), 'icon' => 'server-stack'],
        ]))"
    >
        <x-slot:actions>
            @can('create', App\Models\Server::class)
                @if (multi_surface_active())
                    <a
                        href="{{ route('launches.create') }}"
                        wire:navigate
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Open launchpad') }}
                    </a>
                @else
                    <a
                        href="{{ route('servers.create') }}"
                        wire:navigate
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Create a server') }}
                    </a>
                @endif
            @endcan

            <x-dropdown align="right" width="w-64">
                <x-slot name="trigger">
                    <button type="button" class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        <x-heroicon-o-ellipsis-horizontal class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                        {{ __('More') }}
                    </button>
                </x-slot>
                <x-slot name="content">
                    @can('create', App\Models\Server::class)
                        @feature('surface.managed_servers')
                            <a href="{{ route('servers.create.managed') }}" wire:navigate class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40">
                                <x-heroicon-o-sparkles class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ __('Dply-hosted server') }}
                            </a>
                        @endfeature
                        <a href="{{ route('servers.import.digitalocean') }}" wire:navigate class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40">
                            <x-heroicon-o-cloud-arrow-down class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ __('Import from DigitalOcean') }}
                        </a>
                        <div class="my-1.5 border-t border-brand-ink/10" role="presentation"></div>
                    @endcan
                    <a href="{{ route('docs.create-first-server') }}" wire:navigate class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40">
                        <x-heroicon-o-academic-cap class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                        {{ __('First server guide') }}
                    </a>
                    <a href="{{ route('docs.index') }}" wire:navigate class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40">
                        <x-heroicon-o-document-text class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                        {{ __('Documentation') }}
                    </a>
                </x-slot>
            </x-dropdown>
        </x-slot:actions>

        <x-slot:banners>
            @if (session('success'))
                <x-alert tone="success">{{ session('success') }}</x-alert>
            @endif

            @if ($failedSetups->isNotEmpty())
                <div class="rounded-2xl border border-red-200 bg-red-50/70 px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 text-sm font-semibold text-red-900">
                            <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
                            {{ trans_choice(
                                '{1} :count server failed to finish setting up.|[2,*] :count servers failed to finish setting up.',
                                $failedSetups->count(),
                                ['count' => $failedSetups->count()],
                            ) }}
                        </p>
                        <ul class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm text-red-800">
                            @foreach ($failedSetups as $failed)
                                <li>
                                    <a href="{{ route('servers.journey', $failed) }}" wire:navigate class="font-medium underline-offset-2 hover:underline">
                                        {{ $failed->name }}
                                    </a>
                                    @if ($failed->ip_address)
                                        <span class="text-red-700/70">· {{ $failed->ip_address }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-red-800/80">{{ __('Open the journey to see the failing step and retry the provision, or remove the server.') }}</p>
                    </div>
                </div>
            @endif

            @if ($serverCreateDraft)
                @php
                    $stepLabels = [
                        1 => __('Type & name'),
                        2 => __('Where it runs'),
                        3 => __('What it runs'),
                        4 => __('Review'),
                    ];
                    $stepLabel = $stepLabels[$serverCreateDraft->step] ?? __('In progress');
                @endphp
                <div class="rounded-2xl border border-sky-200 bg-sky-50/70 px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-sky-900">{{ __('You have an in-progress server draft.') }}</p>
                            <p class="mt-0.5 text-sm text-sky-800">{{ __('Step :n of :total · :label · last touched :ago', ['n' => $serverCreateDraft->step, 'total' => \App\Models\ServerCreateDraft::TOTAL_STEPS, 'label' => $stepLabel, 'ago' => $serverCreateDraft->updated_at?->diffForHumans()]) }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="{{ route('servers.create') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-700">
                                {{ __('Continue') }}
                                <x-heroicon-o-arrow-right class="h-4 w-4" />
                            </a>
                            <button type="button" wire:click="openDiscardServerCreateDraftModal" class="inline-flex items-center gap-2 rounded-xl border border-sky-200 bg-white px-4 py-2 text-sm font-semibold text-sky-900 hover:bg-sky-100">
                                {{ __('Discard') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            @unless ($hasProviderCredentials)
                <section class="dply-card overflow-hidden border-amber-200">
                    <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-7">
                        <div class="flex items-start gap-3">
                            <x-icon-badge tone="amber">
                                <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add provider credentials before you provision infrastructure.') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('This fleet can show guidance and empty states, but you will need a connected provider before you can provision cloud infrastructure from the workspace.') }}
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2 sm:items-center">
                            <a href="{{ route('credentials.index') }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                                <x-heroicon-m-key class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Provider credentials') }}
                            </a>
                            <a href="{{ route('docs.connect-provider') }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                <x-heroicon-m-document-text class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Setup guide') }}
                            </a>
                        </div>
                    </div>
                </section>
            @endunless
        </x-slot:banners>

        <x-slot:empty>
            <section class="rounded-[2rem] border-2 border-brand-sage/35 bg-brand-cream shadow-lg shadow-brand-ink/10 ring-1 ring-brand-ink/[0.07]" aria-labelledby="servers-empty-heading">
                <div class="px-6 py-12 text-center sm:px-10 sm:py-14">
                    <div class="mx-auto flex max-w-xl flex-col items-center">
                        <span class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-sand/55 text-brand-forest ring-1 ring-brand-ink/10">
                            <x-heroicon-o-server-stack class="h-9 w-9" aria-hidden="true" />
                        </span>
                        <h2 id="servers-empty-heading" class="mt-6 text-2xl font-semibold tracking-tight text-brand-ink">
                            {{ __('No servers yet') }}
                        </h2>
                        <p class="mt-3 text-base leading-relaxed text-brand-moss">
                            {{ __('Create a VM from here once a cloud provider is connected—or pick a guided path first.') }}
                        </p>
                        <div class="mt-10 flex w-full flex-wrap items-center justify-center gap-3">
                            @can('create', App\Models\Server::class)
                                <a
                                    href="{{ route('servers.create') }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-3 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition hover:bg-brand-forest"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Create a server') }}
                                </a>
                                @foreach ($importSources as $importSource)
                                    @php
                                        $importRoute = match ($importSource) {
                                            'ploi' => route('imports.ploi.inventory'),
                                            'forge' => route('imports.forge.inventory'),
                                            default => null,
                                        };
                                        $importLabel = match ($importSource) {
                                            'ploi' => __('Migrate from Ploi'),
                                            'forge' => __('Migrate from Forge'),
                                            default => __('Migrate'),
                                        };
                                    @endphp
                                    @if ($importRoute !== null)
                                        <a
                                            href="{{ $importRoute }}"
                                            wire:navigate
                                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-amber-300 bg-amber-50 px-5 py-3 text-sm font-semibold text-amber-950 shadow-sm transition hover:bg-amber-100"
                                        >
                                            <x-heroicon-o-arrow-down-tray class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                            {{ $importLabel }}
                                        </a>
                                    @endif
                                @endforeach
                            @endcan
                            <a
                                href="{{ route('credentials.index') }}"
                                wire:navigate
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-sage/40 bg-brand-sand/30 px-5 py-3 text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/50"
                            >
                                <x-heroicon-o-key class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Connect a provider') }}
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </x-slot:empty>

        <x-slot:modals>
            <div x-data="{ deployConsoleOpen: false }" x-on:deploy-console-open.window="deployConsoleOpen = true">
                @if (count($this->watchedRows) > 0)
                    <button
                        type="button"
                        x-show="!deployConsoleOpen"
                        x-on:click="deployConsoleOpen = true"
                        class="fixed bottom-4 left-4 z-40 inline-flex items-center gap-2 rounded-full border border-brand-ink/10 bg-white px-3.5 py-2 text-xs font-semibold text-brand-ink shadow-lg shadow-brand-ink/15 transition hover:bg-brand-sand/40"
                        title="{{ __('Open deploy console') }}"
                    >
                        @if ($this->watchedInProgress)
                            <x-spinner size="sm" />
                            {{ trans_choice('Deploying :n site|Deploying :n sites', count($this->watchedRows), ['n' => count($this->watchedRows)]) }}
                        @else
                            <x-heroicon-m-check-circle class="h-4 w-4 text-emerald-600" />
                            {{ __('Deploys finished') }}
                        @endif
                    </button>
                @endif

                <div x-show="deployConsoleOpen" x-cloak class="fixed inset-0 z-50" style="display: none;">
                    <div class="absolute inset-0 bg-brand-ink/40" x-on:click="deployConsoleOpen = false" x-transition.opacity></div>
                    <div
                        class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="translate-x-full"
                        x-transition:enter-end="translate-x-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="translate-x-0"
                        x-transition:leave-end="translate-x-full"
                    >
                        <div class="flex items-center justify-between border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy console') }}</p>
                                <p class="truncate text-sm font-semibold text-brand-ink">
                                    @if ($this->watchedInProgress)
                                        {{ trans_choice('Deploying :n site|Deploying :n sites', count($this->watchedRows), ['n' => count($this->watchedRows)]) }}
                                    @else
                                        {{ trans_choice('{0}No deploys yet|{1}:n deploy|[2,*]:n deploys', count($this->watchedRows), ['n' => count($this->watchedRows)]) }}
                                    @endif
                                </p>
                            </div>
                            <button type="button" x-on:click="deployConsoleOpen = false" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                                <x-heroicon-o-x-mark class="h-5 w-5" />
                            </button>
                        </div>

                        <div class="min-h-0 flex-1 space-y-2 overflow-y-auto px-5 py-4" @if ($this->watchedInProgress) wire:poll.3s @endif>
                            @forelse ($this->watchedRows as $row)
                                @include('livewire.sites.partials._deploy-console-row', ['row' => $row, 'keyPrefix' => 'fleet'])
                            @empty
                                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-10 text-center text-sm text-brand-moss">
                                    {{ __('Hit Deploy or Sync on a server to watch it here.') }}
                                </div>
                            @endforelse
                        </div>

                        <div class="border-t border-brand-ink/10 bg-brand-sand/20 px-5 py-3 text-center text-[11px] text-brand-moss">
                            @if ($this->watchedInProgress)
                                <span class="inline-flex items-center gap-1.5"><x-spinner size="sm" /> {{ __('Deploying — this updates live.') }}</span>
                            @elseif (count($this->watchedRows) > 0)
                                {{ __('All deploys finished.') }}
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @include('livewire.servers.partials.deploy-sites-modal')

            @include('livewire.servers.partials.remove-server-modal', [
                'open' => $deleteModalServerId !== null && $deleteModalServer,
                'serverName' => $deleteModalServer?->name ?? '',
                'serverId' => (string) ($deleteModalServer?->id ?? ''),
                'deletionSummary' => $deletionSummary,
            ])

            @if ($showDiscardServerCreateDraftModal)
                @teleport('body')
                <div class="fixed inset-0 isolate z-[100] overflow-y-auto" role="dialog" aria-modal="true">
                    <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeDiscardServerCreateDraftModal"></div>
                    <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10">
                        <div class="w-full max-w-md dply-modal-panel" @click.stop>
                            <div class="border-b border-zinc-100 px-6 py-5">
                                <h2 class="text-base font-semibold text-brand-ink">{{ __('Discard this draft?') }}</h2>
                                <p class="mt-2 text-sm text-brand-moss">{{ __("You'll lose the values you've entered so far. This can't be undone.") }}</p>
                            </div>
                            <div class="flex flex-col-reverse gap-3 border-t border-zinc-100 bg-zinc-50/80 px-6 py-4 sm:flex-row sm:justify-end">
                                <button type="button" wire:click="closeDiscardServerCreateDraftModal" class="inline-flex justify-center rounded-xl border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:bg-zinc-50">
                                    {{ __('Keep editing') }}
                                </button>
                                <button type="button" wire:click="confirmDiscardServerCreateDraft" wire:loading.attr="disabled" wire:target="confirmDiscardServerCreateDraft" class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 disabled:cursor-wait disabled:opacity-60">
                                    {{ __('Discard draft') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                @endteleport
            @endif
        </x-slot:modals>
    </x-servers-index-page>
</div>
