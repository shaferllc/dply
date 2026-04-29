<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="text-sm text-brand-moss mb-6" aria-label="{{ __('Breadcrumb') }}">
            <ol class="flex flex-wrap items-center gap-2">
                <li>
                    <a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a>
                </li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Organizations') }}</li>
            </ol>
        </nav>

        @if (session('success'))
            <x-alert tone="success" class="mb-6">{{ session('success') }}</x-alert>
        @endif

        @if ($organizations->isEmpty())
            <div class="flex flex-col items-center rounded-4xl border border-brand-ink/10 bg-brand-sand/15 px-6 py-14 text-center">
                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                    <x-heroicon-o-building-office-2 class="h-9 w-9 text-brand-moss" aria-hidden="true" />
                </div>
                <x-empty-state
                    class="mt-6 border-0 bg-transparent p-0 shadow-none"
                    :title="__('You\'re not in any organization yet.')"
                    :description="__('Create one to manage servers and billing.')"
                    :dashed="false"
                >
                    <x-slot name="actions">
                        <a href="{{ route('organizations.create') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest">
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Create your first organization') }}
                        </a>
                    </x-slot>
                </x-empty-state>
            </div>
        @else
            @php
                $orgTotal = $organizations->count();
                $rollupMembers = $organizations->sum('users_count');
                $rollupTeams = $organizations->sum('teams_count');
                $rollupServers = $organizations->sum('servers_count');
                $rollupSites = $organizations->sum('sites_count');
            @endphp

            <section class="relative mb-8 overflow-hidden rounded-4xl border border-brand-ink/10 bg-brand-cream shadow-sm">
                <div class="absolute inset-0 bg-mesh-brand opacity-[0.08]" aria-hidden="true"></div>
                <div class="relative px-6 py-6 sm:px-8 sm:py-7 lg:px-10">
                    <x-page-header
                        :title="__('Organizations')"
                        :description="__('Switch between workspaces, review usage at a glance, and jump into the right organization shell.')"
                        doc-route="docs.markdown"
                        doc-slug="org-roles-and-limits"
                        :doc-label="__('Roles & limits')"
                        flush
                        compact
                    >
                        <x-slot name="leading">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                                <x-heroicon-o-building-office-2 class="h-7 w-7 text-brand-ink" aria-hidden="true" />
                            </span>
                        </x-slot>
                        <x-slot name="actions">
                            <a href="{{ route('organizations.create') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest">
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('New organization') }}
                            </a>
                        </x-slot>
                    </x-page-header>

                    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/80 px-4 py-3 shadow-sm backdrop-blur-sm">
                            <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                <x-heroicon-o-building-office-2 class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ __('Workspaces') }}
                            </div>
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $orgTotal }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/80 px-4 py-3 shadow-sm backdrop-blur-sm">
                            <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                <x-heroicon-o-user-group class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ __('Members') }}
                            </div>
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $rollupMembers }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/80 px-4 py-3 shadow-sm backdrop-blur-sm">
                            <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                <x-heroicon-o-squares-2x2 class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ __('Teams') }}
                            </div>
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $rollupTeams }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/80 px-4 py-3 shadow-sm backdrop-blur-sm">
                            <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                <x-heroicon-o-server-stack class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ __('Servers') }}
                            </div>
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $rollupServers }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/80 px-4 py-3 shadow-sm backdrop-blur-sm sm:col-span-2 lg:col-span-1">
                            <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ __('Sites') }}
                            </div>
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $rollupSites }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <x-section-card padding="none">
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($organizations as $org)
                        @php
                            $initials = collect(preg_split('/\s+/', trim($org->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
                            if ($initials === '') {
                                $initials = mb_strtoupper(mb_substr((string) $org->name, 0, 2));
                            }
                        @endphp
                        <li class="px-4 py-5 transition-colors hover:bg-brand-sand/25 sm:px-6">
                            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between lg:gap-8">
                                <div class="flex min-w-0 flex-1 gap-4">
                                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-brand-sand/40 text-sm font-bold tracking-tight text-brand-ink shadow-sm" aria-hidden="true">
                                        <span class="select-none">{{ $initials }}</span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('organizations.show', $org) }}" wire:navigate class="truncate text-base font-semibold text-brand-ink hover:text-brand-sage">{{ $org->name }}</a>
                                            @if (session('current_organization_id') == $org->id)
                                                <x-badge tone="accent" size="sm">{{ __('Current') }}</x-badge>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-sm text-brand-moss">
                                            {{ __('Quick overview of members, teams, infrastructure, and app footprint.') }}
                                        </p>
                                        <div class="mt-4 flex flex-wrap gap-2">
                                            <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                                <x-heroicon-o-user-group class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                                <span class="tabular-nums text-brand-ink">{{ $org->users_count }} {{ Str::plural('member', $org->users_count) }}</span>
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                                <x-heroicon-o-squares-2x2 class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                                <span class="tabular-nums text-brand-ink">{{ $org->teams_count }} {{ Str::plural('team', $org->teams_count) }}</span>
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                                <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                                <span class="tabular-nums text-brand-ink">{{ $org->servers_count }} {{ Str::plural('server', $org->servers_count) }}</span>
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                                <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                                <span class="tabular-nums text-brand-ink">{{ $org->sites_count }} {{ Str::plural('site', $org->sites_count) }}</span>
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                                <x-heroicon-o-rectangle-stack class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                                <span class="tabular-nums text-brand-ink">{{ $org->workspaces_count }} {{ Str::plural('project', $org->workspaces_count) }}</span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                    @if (session('current_organization_id') != $org->id)
                                        <button
                                            type="button"
                                            wire:click="switchOrganization('{{ $org->id }}')"
                                            class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:border-brand-ink/25 hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-arrow-path-rounded-square class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                            {{ __('Switch') }}
                                        </button>
                                    @endif
                                    <a
                                        href="{{ route('organizations.show', $org) }}"
                                        wire:navigate
                                        class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-brand-ink/15 bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                                    >
                                        {{ __('Overview') }}
                                        <x-heroicon-o-arrow-up-right class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                    </a>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-section-card>
        @endif
    </div>
</div>
