@php
    $orgTotal = $allOrganizations->count();
    $rollupMembers = $allOrganizations->sum('users_count');
    $rollupServers = $allOrganizations->sum('servers_count');
    $rollupSites = $allOrganizations->sum('sites_count');
    $currentOrgId = session('current_organization_id');
    $hasOrgSearch = trim($search ?? '') !== '';
    $filteredCount = $organizations->count();
    // Header Add only when the list already has items — empty state owns the CTA.
    $showShellAdd = $orgTotal > 0;
    // Search earns its space only once the list stops fitting on screen.
    $showOrgSearch = $orgTotal > 8 || $hasOrgSearch;
    $summaryLine = collect([
        trans_choice(':count organization|:count organizations', $orgTotal, ['count' => $orgTotal]),
        $rollupMembers > 0 ? trans_choice(':count member|:count members', $rollupMembers, ['count' => $rollupMembers]) : null,
        $rollupServers > 0 ? trans_choice(':count server|:count servers', $rollupServers, ['count' => $rollupServers]) : null,
        $rollupSites > 0 ? trans_choice(':count site|:count sites', $rollupSites, ['count' => $rollupSites]) : null,
    ])->filter()->implode(' · ');
@endphp

<div>
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <x-dashboard-breadcrumb doc-route="docs.markdown" doc-slug="org-roles-and-limits" :current="__('Organizations')" current-icon="building-office-2" />

        <x-profile-shell
            dense
            :title="__('Organizations')"
            :description="$orgTotal === 0 ? null : $summaryLine"
            icon="heroicon-o-building-office-2"
        >
            <x-slot:actions>
                @if ($showShellAdd)
                    <a
                        href="{{ route('organizations.create') }}"
                        wire:navigate
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('New organization') }}
                    </a>
                @endif
            </x-slot:actions>

            @if (session('success'))
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <x-alert tone="success">{{ session('success') }}</x-alert>
                </div>
            @endif

            @if ($orgTotal === 0)
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-building-office-2 class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No organizations yet') }}</p>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Create a workspace to group servers, teams, and billing in one place.') }}
                    </p>
                    <a
                        href="{{ route('organizations.create') }}"
                        wire:navigate
                        class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Create your first organization') }}
                    </a>
                </div>
            @else
                @if ($showOrgSearch)
                    <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-5 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-6">
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
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No organizations match this search.') }}</p>
                        <button type="button" wire:click="$set('search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                    </div>
                @else
                    @php $th = 'px-3 py-2.5 text-start text-2xs font-semibold uppercase tracking-wide text-brand-mist sm:px-5'; @endphp
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[44rem] border-collapse text-sm">
                            <thead>
                                <tr class="border-b border-brand-ink/10">
                                    <th scope="col" class="{{ $th }}">{{ __('Organization') }}</th>
                                    <th scope="col" class="{{ $th }}">{{ __('Members') }}</th>
                                    <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Teams') }}</th>
                                    <th scope="col" class="{{ $th }}">{{ __('Servers') }}</th>
                                    <th scope="col" class="{{ $th }}">{{ __('Sites') }}</th>
                                    <th scope="col" class="{{ $th }} hidden lg:table-cell">{{ __('Projects') }}</th>
                                    <th scope="col" class="{{ $th }}"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($organizations as $org)
                                    @php $isCurrent = $currentOrgId == $org->id; @endphp
                                    <tr wire:key="org-{{ $org->id }}" @class([
                                        'group border-b border-brand-ink/10 transition-colors last:border-b-0 hover:bg-brand-sand/15',
                                        'bg-brand-sage/5' => $isCurrent,
                                    ])>
                                        <td class="max-w-[18rem] px-3 py-2.5 sm:px-5">
                                            <div class="flex items-center gap-2">
                                                <a href="{{ route('organizations.show', $org) }}" wire:navigate class="min-w-0 truncate font-semibold text-brand-ink transition-colors hover:text-brand-sage" title="{{ $org->name }}">
                                                    {{ $org->name }}
                                                </a>
                                                @if ($isCurrent)
                                                    <span class="inline-flex shrink-0 items-center rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest">
                                                        {{ __('Current') }}
                                                    </span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2.5 font-mono tabular-nums text-brand-moss sm:px-5">{{ $org->users_count }}</td>
                                        <td class="hidden whitespace-nowrap px-3 py-2.5 font-mono tabular-nums text-brand-moss sm:table-cell sm:px-5">{{ $org->teams_count }}</td>
                                        <td class="whitespace-nowrap px-3 py-2.5 font-mono tabular-nums text-brand-moss sm:px-5">{{ $org->servers_count }}</td>
                                        <td class="whitespace-nowrap px-3 py-2.5 font-mono tabular-nums text-brand-moss sm:px-5">{{ $org->sites_count }}</td>
                                        <td class="hidden whitespace-nowrap px-3 py-2.5 font-mono tabular-nums text-brand-moss sm:px-5 lg:table-cell">{{ $org->workspaces_count }}</td>
                                        <td class="px-3 py-2.5 sm:px-5">
                                            <div class="flex items-center justify-end gap-1.5 transition-opacity focus-within:opacity-100 sm:opacity-0 sm:group-hover:opacity-100">
                                                @if (! $isCurrent)
                                                    <button
                                                        type="button"
                                                        wire:click="switchOrganization('{{ $org->id }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="switchOrganization('{{ $org->id }}')"
                                                        class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                                                    >
                                                        <span wire:loading.remove wire:target="switchOrganization('{{ $org->id }}')">{{ __('Switch') }}</span>
                                                        <span wire:loading wire:target="switchOrganization('{{ $org->id }}')" class="inline-flex items-center gap-1.5">
                                                            <x-spinner variant="forest" size="sm" />
                                                            {{ __('Switching…') }}
                                                        </span>
                                                    </button>
                                                @endif
                                                <a
                                                    href="{{ route('organizations.show', $org) }}"
                                                    wire:navigate
                                                    class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-brand-cream transition hover:bg-brand-forest sm:px-3"
                                                >
                                                    {{ __('Overview') }}
                                                    <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </x-profile-shell>
    </div>
</div>
