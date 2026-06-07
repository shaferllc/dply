@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $orgTotal = $allOrganizations->count();
    $rollupMembers = $allOrganizations->sum('users_count');
    $rollupTeams = $allOrganizations->sum('teams_count');
    $rollupServers = $allOrganizations->sum('servers_count');
    $rollupSites = $allOrganizations->sum('sites_count');
    $currentOrgId = session('current_organization_id');
    $hasOrgSearch = trim($search ?? '') !== '';
    $filteredCount = $organizations->count();
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-dashboard-breadcrumb :current="__('Organizations')" current-icon="building-office-2" />

        @if (session('success'))
            <div class="mb-4">
                <x-alert tone="success">{{ session('success') }}</x-alert>
            </div>
        @endif

        @if ($orgTotal === 0)
            {{-- Hero (empty variant) — same shell so the page never collapses to a bare card. --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <x-icon-badge size="md">
                                <x-heroicon-o-building-office-2 class="h-6 w-6" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Workspaces') }}</p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Organizations') }}</h2>
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ __("You're not in any organization yet. Spin one up to start grouping servers, teams, and billing.") }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <x-docs-link doc-route="docs.markdown" doc-slug="org-roles-and-limits">
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Roles & limits') }}
                            </x-docs-link>
                            <a
                                href="{{ route('organizations.create') }}"
                                wire:navigate
                                class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create your first organization') }}
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        @else
            {{-- Hero: positioning + at-a-glance rollups. --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <x-icon-badge size="md">
                                <x-heroicon-o-building-office-2 class="h-6 w-6" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Workspaces') }}</p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Organizations') }}</h2>
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Switch workspaces, review usage, and open the organization you need.') }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <x-docs-link doc-route="docs.markdown" doc-slug="org-roles-and-limits">
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Roles & limits') }}
                            </x-docs-link>
                            <a
                                href="{{ route('organizations.create') }}"
                                wire:navigate
                                class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('New organization') }}
                            </a>
                        </div>
                    </div>
                    <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                        <div @class([
                            'rounded-2xl border px-4 py-3 shadow-sm',
                            'border-brand-sage/30 bg-brand-sage/8' => $orgTotal > 0,
                            'border-brand-ink/10 bg-white' => $orgTotal === 0,
                        ])>
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Workspaces') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $orgTotal }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('total|total', $orgTotal) }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('You belong to') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Members') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $rollupMembers }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('person|people', $rollupMembers) }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Across all orgs') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Footprint') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $rollupServers + $rollupSites }}</span>
                                <span class="text-[11px] text-brand-moss">{{ __('resources') }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ $rollupServers }} {{ trans_choice('server|servers', $rollupServers) }} · {{ $rollupSites }} {{ trans_choice('site|sites', $rollupSites) }}</p>
                        </div>
                    </dl>
                </div>
            </section>

            <div class="mt-6 space-y-6">
                {{-- Workspace list --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Your organizations') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Jump into a workspace or switch the one you’re currently active in.') }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                                <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $orgTotal }}</span>
                                <a
                                    href="{{ route('organizations.create') }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('New organization') }}
                                </a>
                            </div>
                    </div>

                    {{-- Toolbar: search. --}}
                    @if ($orgTotal > 1 || $hasOrgSearch)
                        <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-6 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-7">
                            <div class="w-full sm:max-w-sm">
                                <label for="org_search" class="sr-only">{{ __('Search') }}</label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist">
                                        <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                    <input
                                        id="org_search"
                                        type="search"
                                        wire:model.live.debounce.300ms="search"
                                        placeholder="{{ __('Search organizations by name…') }}"
                                        autocomplete="off"
                                        class="w-full rounded-lg border-brand-ink/15 bg-white py-2 ps-9 pe-3 text-sm text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    />
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($hasOrgSearch && $filteredCount === 0)
                        <div class="px-6 py-12 text-center sm:px-7">
                            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No organizations match this search.') }}</p>
                            <button type="button" wire:click="$set('search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($organizations as $org)
                                @php
                                    $initials = collect(preg_split('/\s+/', trim($org->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
                                    if ($initials === '') {
                                        $initials = mb_strtoupper(mb_substr((string) $org->name, 0, 2));
                                    }
                                    $isCurrent = $currentOrgId == $org->id;
                                @endphp
                                <li wire:key="org-{{ $org->id }}" @class([
                                    'flex flex-col gap-4 px-6 py-4 transition-colors hover:bg-brand-sand/15 sm:px-7 lg:flex-row lg:items-center lg:justify-between lg:gap-6',
                                    'bg-brand-sage/5' => $isCurrent,
                                ])>
                                    <div class="flex min-w-0 flex-1 items-start gap-4">
                                        <span @class([
                                            'flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-sm font-bold tracking-tight shadow-sm ring-1',
                                            'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => $isCurrent,
                                            'bg-brand-sand/40 text-brand-ink ring-brand-ink/10' => ! $isCurrent,
                                        ]) aria-hidden="true">
                                            <span class="select-none">{{ $initials }}</span>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <a href="{{ route('organizations.show', $org) }}" wire:navigate class="truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">{{ $org->name }}</a>
                                                @if ($isCurrent)
                                                    <span class="inline-flex items-center gap-1 rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">
                                                        <x-heroicon-m-check-circle class="h-3 w-3" aria-hidden="true" />
                                                        {{ __('Current') }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-moss">
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-m-user-group class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    <span class="font-mono tabular-nums text-brand-ink">{{ $org->users_count }}</span>
                                                    {{ trans_choice('member|members', $org->users_count) }}
                                                </span>
                                                <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-m-squares-2x2 class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    <span class="font-mono tabular-nums text-brand-ink">{{ $org->teams_count }}</span>
                                                    {{ trans_choice('team|teams', $org->teams_count) }}
                                                </span>
                                                <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-m-server-stack class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    <span class="font-mono tabular-nums text-brand-ink">{{ $org->servers_count }}</span>
                                                    {{ trans_choice('server|servers', $org->servers_count) }}
                                                </span>
                                                <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-m-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    <span class="font-mono tabular-nums text-brand-ink">{{ $org->sites_count }}</span>
                                                    {{ trans_choice('site|sites', $org->sites_count) }}
                                                </span>
                                                <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-m-rectangle-stack class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    <span class="font-mono tabular-nums text-brand-ink">{{ $org->workspaces_count }}</span>
                                                    {{ trans_choice('project|projects', $org->workspaces_count) }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                        @if (! $isCurrent)
                                            <button
                                                type="button"
                                                wire:click="switchOrganization('{{ $org->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="switchOrganization('{{ $org->id }}')"
                                                class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                                            >
                                                <span wire:loading.remove wire:target="switchOrganization('{{ $org->id }}')" class="inline-flex items-center gap-1.5">
                                                    <x-heroicon-o-arrow-path-rounded-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    {{ __('Switch') }}
                                                </span>
                                                <span wire:loading wire:target="switchOrganization('{{ $org->id }}')" class="inline-flex items-center gap-1.5">
                                                    <x-spinner variant="forest" size="sm" />
                                                    {{ __('Switching…') }}
                                                </span>
                                            </button>
                                        @endif
                                        <a
                                            href="{{ route('organizations.show', $org) }}"
                                            wire:navigate
                                            class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                                        >
                                            {{ __('Overview') }}
                                            <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                        </a>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        @endif
    </div>
</div>
