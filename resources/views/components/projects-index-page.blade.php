@props([
    /** @var \Illuminate\Support\Collection<int, \App\Support\Projects\ProjectIndexRow> */
    'rows',
    /** @var array{projects: int, servers: int, sites: int, members: int} */
    'summary' => ['projects' => 0, 'servers' => 0, 'sites' => 0, 'members' => 0],
    'hasProjectsInScope' => true,
    'hasOrganization' => true,
    /** @var \Illuminate\Support\Collection<int, \App\Models\WorkspaceLabel>|iterable */
    'labels' => [],
    /** @var list<string>|array<string, string> */
    'workspaceRoles' => [],
    /** @var list<array{label: string, href?: string, icon?: string}> */
    'breadcrumbs' => [],
    'search' => '',
    'labelFilter' => '',
    'roleFilter' => '',
    'showFilters' => true,
    'showCreateAction' => false,
    'emptyState' => 'local',
    'eyebrow' => null,
])

@php
    $isProductionSurface = $emptyState === 'production';
    $projectsTotal = (int) ($summary['projects'] ?? 0);
    $serversTotal = (int) ($summary['servers'] ?? 0);
    $sitesTotal = (int) ($summary['sites'] ?? 0);
    $membersTotal = (int) ($summary['members'] ?? 0);
    $resourceTotal = $serversTotal + $sitesTotal;
    $filtersActive = trim((string) $search) !== ''
        || trim((string) $labelFilter) !== ''
        || trim((string) $roleFilter) !== '';
    $showStats = $hasOrganization && $projectsTotal > 0;
    // Search/filter chrome earns its space only once the list stops fitting.
    $showToolbar = $showFilters && $projectsTotal > 8;
    // Livewire wraps every @if/@foreach in `<!--[if BLOCK]>` markers, so a slot
    // whose conditions all fail is still a non-empty string — strip them first.
    $alertHtml = isset($alert) ? trim(preg_replace('/<!--.*?-->/s', '', (string) $alert) ?? '') : '';
    $summaryLine = collect([
        trans_choice(':count project|:count projects', $projectsTotal, ['count' => $projectsTotal]),
        $serversTotal > 0 ? trans_choice(':count server|:count servers', $serversTotal, ['count' => $serversTotal]) : null,
        $sitesTotal > 0 ? trans_choice(':count site|:count sites', $sitesTotal, ['count' => $sitesTotal]) : null,
        (! $isProductionSurface && $membersTotal > 0) ? trans_choice(':count member|:count members', $membersTotal, ['count' => $membersTotal]) : null,
    ])->filter()->implode(' · ');
    $showShellCreate = $showCreateAction && $hasProjectsInScope && $projectsTotal > 0;
    $summaryStats = [
        [
            'icon' => 'heroicon-o-rectangle-group',
            'label' => __('Projects'),
            'value' => $projectsTotal,
            'tone' => 'text-brand-sage',
            'hint' => __('You can access'),
        ],
        [
            'icon' => 'heroicon-o-server-stack',
            'label' => __('Footprint'),
            'value' => $resourceTotal,
            'tone' => 'text-brand-sage',
            'hint' => $serversTotal.' '.trans_choice('server|servers', $serversTotal).' · '.$sitesTotal.' '.trans_choice('site|sites', $sitesTotal),
        ],
    ];
    if (! $isProductionSurface) {
        $summaryStats[] = [
            'icon' => 'heroicon-o-user-group',
            'label' => __('Members'),
            'value' => $membersTotal,
            'tone' => 'text-brand-sage',
            'hint' => __('Across all projects'),
        ];
    }
@endphp

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="$breadcrumbs" />

    <x-profile-shell
        dense
        :title="__('Projects')"
        :description="! $hasOrganization
            ? __('Select an organization from the header.')
            : ($showStats ? $summaryLine : null)"
        icon="heroicon-o-rectangle-group"
    >
        @if ($showShellCreate || isset($actions))
            <x-slot:actions>
                @if ($showShellCreate)
                    <button
                        type="button"
                        wire:click="openCreateProjectModal"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('New project') }}
                    </button>
                @endif
                @isset($actions)
                    {{ $actions }}
                @endisset
            </x-slot:actions>
        @endif

        @if ($alertHtml !== '')
            <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                {{ $alert }}
            </div>
        @endif

        @unless ($hasOrganization)
            <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="projects-empty-heading">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                    <x-heroicon-o-rectangle-group class="h-6 w-6" aria-hidden="true" />
                </span>
                <h2 id="projects-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">{{ __('Select an organization') }}</h2>
                <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                    {{ __('Projects belong to an organization. Pick one from the header to continue.') }}
                </p>
            </div>
        @elseif (! $hasProjectsInScope)
            @if (isset($empty) && ! $empty->isEmpty())
                {{ $empty }}
            @else
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="projects-empty-heading">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-rectangle-group class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <h2 id="projects-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">
                        {{ $isProductionSurface ? __('No production projects') : __('No projects yet') }}
                    </h2>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ $isProductionSurface
                            ? __('The connected control plane returned no projects for this organization.')
                            : __('Create a project to attach servers and sites and invite members.') }}
                    </p>
                    @if ($showCreateAction && ! $isProductionSurface)
                        <button
                            type="button"
                            wire:click="openCreateProjectModal"
                            class="mt-5 inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('New project') }}
                        </button>
                    @endif
                </div>
            @endif
        @else
            @if ($showToolbar)
                <div class="flex items-center gap-2 border-b border-brand-ink/10 px-3 py-3 sm:px-5">
                    <div class="min-w-0 flex-1">
                        <label for="projects_search" class="sr-only">{{ __('Search') }}</label>
                        <x-text-input
                            id="projects_search"
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            class="mt-0 w-full"
                            placeholder="{{ __('Search by name, notes, or description…') }}"
                            autocomplete="off"
                        />
                    </div>

                    <x-dropdown align="right" width="w-72" content-classes="p-3" :close-on-content-click="false">
                        <x-slot name="trigger">
                            <button
                                type="button"
                                class="relative inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-brand-ink/15 bg-white text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink"
                                title="{{ __('Filters') }}"
                            >
                                <span class="sr-only">{{ __('Filters') }}</span>
                                <x-heroicon-o-funnel class="h-4 w-4" aria-hidden="true" />
                                @if ($filtersActive)
                                    <span class="absolute end-1.5 top-1.5 h-1.5 w-1.5 rounded-full bg-brand-forest ring-2 ring-white" aria-hidden="true"></span>
                                @endif
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            <div class="space-y-3">
                                <div>
                                    <label for="projects_label" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Label') }}</label>
                                    <x-select id="projects_label" wire:model.live="labelFilter" class="mt-1.5 w-full">
                                        <option value="">{{ __('All labels') }}</option>
                                        @foreach ($labels as $label)
                                            <option value="{{ $label->id }}">{{ $label->name }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                                <div>
                                    <label for="projects_role" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('My role') }}</label>
                                    <x-select id="projects_role" wire:model.live="roleFilter" class="mt-1.5 w-full">
                                        <option value="">{{ __('Any role') }}</option>
                                        @foreach ($workspaceRoles as $role)
                                            <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                                <div class="flex justify-end border-t border-brand-ink/10 pt-3">
                                    <button
                                        type="button"
                                        wire:click="clearFilters"
                                        @@click="$dispatch('close')"
                                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink"
                                    >
                                        {{ __('Reset') }}
                                    </button>
                                </div>
                            </div>
                        </x-slot>
                    </x-dropdown>
                </div>
            @endif

            @if ($rows->isEmpty())
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No projects match your current filters') }}</p>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Try widening the search, switching a filter, or resetting to bring every project back into view.') }}
                    </p>
                    @if ($showFilters)
                        <button type="button" wire:click="clearFilters" class="mt-4 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                            {{ __('Reset filters') }}
                        </button>
                    @endif
                </div>
            @else
                @php $th = 'px-3 py-2.5 text-start text-2xs font-semibold uppercase tracking-wide text-brand-mist sm:px-5'; @endphp
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[38rem] border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-brand-ink/10">
                                <th scope="col" class="{{ $th }}">{{ __('Project') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Servers') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Sites') }}</th>
                                <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Members') }}</th>
                                <th scope="col" class="{{ $th }} hidden lg:table-cell">{{ __('Your role') }}</th>
                                <th scope="col" class="{{ $th }}"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $project)
                                @include('components.partials.project-index-card', ['project' => $project])
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endunless
    </x-profile-shell>

    {{ $modals ?? '' }}
</div>
