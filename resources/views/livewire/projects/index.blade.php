@php
    $resourceTotal = $serversTotal + $sitesTotal;
    $hasFilters = trim($search ?? '') !== '' || ($labelFilter ?? '') !== '' || ($roleFilter ?? '') !== '';
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-dashboard-breadcrumb :current="__('Projects')" current-icon="rectangle-group" doc-route="docs.markdown" doc-slug="projects-overview" />

        @if (! $hasOrganization)
            {{-- Hero (no-org variant) — same shell so the page never collapses to a bare card. --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <x-icon-badge size="md">
                                <x-heroicon-o-rectangle-group class="h-6 w-6" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Workspaces') }}</p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Projects') }}</h2>
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Select an organization from the header to group servers, sites, and member access into projects.') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @else
            {{-- Hero: positioning + at-a-glance rollups across every project you can see. --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <x-icon-badge size="md">
                                <x-heroicon-o-rectangle-group class="h-6 w-6" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Workspaces') }}</p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Projects') }}</h2>
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Group servers, sites, and member access for each initiative your team is running.') }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            @can('create', App\Models\Workspace::class)
                                <button
                                    type="button"
                                    wire:click="openCreateProjectModal"
                                    class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('New project') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                    <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                        <div @class([
                            'rounded-2xl border px-4 py-3 shadow-sm',
                            'border-brand-sage/30 bg-brand-sage/8' => $projectsTotal > 0,
                            'border-brand-ink/10 bg-white' => $projectsTotal === 0,
                        ])>
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Projects') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $projectsTotal }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('total|total', $projectsTotal) }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('You can access') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Footprint') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $resourceTotal }}</span>
                                <span class="text-[11px] text-brand-moss">{{ __('resources') }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ $serversTotal }} {{ trans_choice('server|servers', $serversTotal) }} · {{ $sitesTotal }} {{ trans_choice('site|sites', $sitesTotal) }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Members') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $membersTotal }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('seat|seats', $membersTotal) }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Across all projects') }}</p>
                        </div>
                    </dl>
                </div>
            </section>

            <div class="mt-6 space-y-6">
                {{-- Project list --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Your projects') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Open a project to attach servers and sites or manage who has access.') }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $projectsTotal }}</span>
                            @can('create', App\Models\Workspace::class)
                                <button
                                    type="button"
                                    wire:click="openCreateProjectModal"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('New project') }}
                                </button>
                            @endcan
                        </div>
                    </div>

                    {{-- Toolbar: search + filters. --}}
                    <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-6 py-3 sm:px-7 lg:flex-row lg:items-center">
                        <div class="w-full lg:max-w-sm">
                            <label for="project-search" class="sr-only">{{ __('Search') }}</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist">
                                    <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <input
                                    id="project-search"
                                    type="search"
                                    wire:model.live.debounce.300ms="search"
                                    placeholder="{{ __('Search by name, notes, or description…') }}"
                                    autocomplete="off"
                                    class="w-full rounded-lg border-brand-ink/15 bg-white py-2 ps-9 pe-3 text-sm text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                />
                            </div>
                        </div>
                        <div class="flex flex-1 flex-wrap items-center gap-2 lg:justify-end">
                            <label for="project-label-filter" class="sr-only">{{ __('Label') }}</label>
                            <select id="project-label-filter" wire:model.live="labelFilter" class="rounded-lg border-brand-ink/15 bg-white py-2 pe-8 ps-3 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                <option value="">{{ __('All labels') }}</option>
                                @foreach ($labels as $label)
                                    <option value="{{ $label->id }}">{{ $label->name }}</option>
                                @endforeach
                            </select>
                            <label for="project-role-filter" class="sr-only">{{ __('My role') }}</label>
                            <select id="project-role-filter" wire:model.live="roleFilter" class="rounded-lg border-brand-ink/15 bg-white py-2 pe-8 ps-3 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                <option value="">{{ __('Any role') }}</option>
                                @foreach ($workspaceRoles as $role)
                                    <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                                @endforeach
                            </select>
                            @if ($hasFilters)
                                <button type="button" wire:click="clearFilters" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                    <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Clear') }}
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Saved views + save current filter set. --}}
                    <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-6 py-3 sm:px-7 lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Saved views') }}</span>
                            @forelse ($views as $view)
                                <button
                                    type="button"
                                    wire:click="applySavedView('{{ $view->id }}')"
                                    class="rounded-full border border-brand-mist bg-white px-3 py-1 text-xs font-medium text-brand-ink shadow-sm ring-1 ring-brand-ink/5 transition hover:bg-brand-sand/30"
                                >
                                    {{ $view->name }}
                                </button>
                            @empty
                                <span class="text-xs text-brand-mist">{{ __('None yet — save a filter set to reuse it later.') }}</span>
                            @endforelse
                        </div>
                        <form wire:submit.prevent="saveView" class="flex items-center gap-2">
                            <label for="saved-view-name" class="sr-only">{{ __('Save this filter set') }}</label>
                            <input
                                id="saved-view-name"
                                type="text"
                                wire:model="savedViewName"
                                placeholder="{{ __('Name this view…') }}"
                                class="w-44 rounded-lg border-brand-ink/15 bg-white py-1.5 px-3 text-sm text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            />
                            <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                {{ __('Save view') }}
                            </button>
                        </form>
                    </div>

                    @if ($workspaces->isEmpty())
                        <div class="px-6 py-12 text-center sm:px-7">
                            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-rectangle-group class="h-5 w-5" aria-hidden="true" />
                            </span>
                            @if ($hasFilters)
                                <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No projects match these filters.') }}</p>
                                <button type="button" wire:click="clearFilters" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear filters') }}</button>
                            @else
                                <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No projects yet.') }}</p>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Create a project to attach servers and sites and invite members.') }}</p>
                                @can('create', App\Models\Workspace::class)
                                    <button type="button" wire:click="openCreateProjectModal" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest">
                                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('New project') }}
                                    </button>
                                @endcan
                            @endif
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($workspaces as $w)
                                @php
                                    $membership = $w->members->firstWhere('user_id', auth()->id());
                                    $initials = collect(preg_split('/\s+/', trim($w->name)))->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
                                    if ($initials === '') {
                                        $initials = mb_strtoupper(mb_substr((string) $w->name, 0, 2));
                                    }
                                @endphp
                                <li wire:key="project-{{ $w->id }}" class="flex flex-col gap-4 px-6 py-4 transition-colors hover:bg-brand-sand/15 sm:px-7 lg:flex-row lg:items-center lg:justify-between lg:gap-6">
                                    <div class="flex min-w-0 flex-1 items-start gap-4">
                                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-sm font-bold tracking-tight text-brand-ink shadow-sm ring-1 ring-brand-ink/10" aria-hidden="true">
                                            <span class="select-none">{{ $initials }}</span>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <a href="{{ route('projects.show', $w) }}" wire:navigate class="truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">{{ $w->name }}</a>
                                                @if ($membership)
                                                    <x-badge size="sm">{{ ucfirst($membership->role) }}</x-badge>
                                                @endif
                                                @foreach ($w->labels as $label)
                                                    <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $label->name }}</span>
                                                @endforeach
                                            </div>
                                            @if ($w->description)
                                                <p class="mt-1 max-w-xl text-sm text-brand-moss line-clamp-2">{{ $w->description }}</p>
                                            @endif
                                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-moss">
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-m-server-stack class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    <span class="font-mono tabular-nums text-brand-ink">{{ $w->servers_count }}</span>
                                                    {{ trans_choice('server|servers', $w->servers_count) }}
                                                </span>
                                                <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-m-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    <span class="font-mono tabular-nums text-brand-ink">{{ $w->sites_count }}</span>
                                                    {{ trans_choice('site|sites', $w->sites_count) }}
                                                </span>
                                                <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-m-user-group class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    <span class="font-mono tabular-nums text-brand-ink">{{ $w->members->count() }}</span>
                                                    {{ trans_choice('member|members', $w->members->count()) }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                        <a
                                            href="{{ route('projects.show', $w) }}"
                                            wire:navigate
                                            class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                                        >
                                            {{ __('Manage') }}
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

    @if ($hasOrganization)
        @can('create', App\Models\Workspace::class)
        <x-modal
            name="create-project-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="createProject">
                <div class="border-b border-brand-ink/10 px-6 py-5 dark:border-brand-mist/20">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New project') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Create a project') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-brand-moss">
                        {{ __('Group servers and sites, then invite members with roles that fit how your team works.') }}
                    </p>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="proj-name-modal" :value="__('Name')" />
                        <x-text-input
                            id="proj-name-modal"
                            wire:model="name"
                            type="text"
                            class="mt-2 block w-full"
                            required
                            maxlength="120"
                            autocomplete="off"
                        />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="proj-desc-modal" :value="__('Description (optional)')" />
                        <x-textarea id="proj-desc-modal" wire:model="description" rows="3" class="mt-2 block w-full" />
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4 dark:border-brand-mist/20">
                    <x-secondary-button type="button" wire:click="closeCreateProjectModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createProject">
                        <span wire:loading.remove wire:target="createProject">{{ __('Create project') }}</span>
                        <span wire:loading wire:target="createProject" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Creating…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>
        @endcan
    @endif
</div>
