@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $isAdmin = $organization->hasAdminAccess(auth()->user());
    $teamCount = $organization->teams->count();
    $totalMemberSlots = $organization->teams->sum(fn ($t) => $t->users->count());
    $orgMemberCount = $organization->users->count();
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="teams" :breadcrumb="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
            ['label' => __('Teams'), 'icon' => 'rectangle-group'],
        ]">
            <x-livewire-validation-errors />

            {{-- Hero: positioning + at-a-glance counts. --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <x-icon-badge size="md">
                                <x-heroicon-o-rectangle-group class="h-6 w-6" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Collaboration') }}</p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Teams') }}</h2>
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Group members to scope servers, sites, and notifications. Each member can belong to multiple teams.') }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <x-docs-link slug="org-teams">
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Teams guide') }}
                            </x-docs-link>
                            <x-docs-link slug="org-roles-and-limits">
                                <x-heroicon-o-queue-list class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Roles & limits') }}
                            </x-docs-link>
                            <x-outline-link href="{{ route('organizations.members', $organization) }}" wire:navigate>
                                <x-heroicon-o-user-group class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Members') }}
                            </x-outline-link>
                            @if ($isAdmin)
                                <button
                                    type="button"
                                    wire:click="openCreateTeamModal"
                                    class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Create team') }}
                                </button>
                            @endif
                        </div>
                    </div>
                    <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Teams') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $teamCount }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('total|total', $teamCount) }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('In this organization') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Memberships') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $totalMemberSlots }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('slot|slots', $totalMemberSlots) }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Across all teams') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Members') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $orgMemberCount }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('available|available', $orgMemberCount) }}</span>
                            </dd>
                            <a href="{{ route('organizations.members', $organization) }}" wire:navigate class="mt-1 inline-flex text-[11px] font-semibold text-brand-sage hover:text-brand-ink">{{ __('Manage') }} →</a>
                        </div>
                    </dl>
                </div>
            </section>

            <div class="mt-6 space-y-6">
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-user-group class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Directory') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Your teams') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Rename teams, manage membership, or open per-team notification channels.') }}</p>
                        </div>
                        @if ($isAdmin)
                                <button
                                    type="button"
                                    wire:click="openCreateTeamModal"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('New team') }}
                                </button>
                            @endif
                    </div>

                    <div class="p-6 sm:p-7">
                        @if ($organization->teams->isEmpty())
                            <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-6 py-12 text-center">
                                <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                    <x-heroicon-o-rectangle-group class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No teams yet.') }}</p>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Teams let you scope notifications and group access.') }}</p>
                                @if ($isAdmin)
                                    <button type="button" wire:click="openCreateTeamModal" class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Create your first team') }}
                                    </button>
                                @endif
                            </div>
                        @else
                            <ul class="space-y-4">
                                    @foreach ($organization->teams as $team)
                                        @php
                                            $membersAvailableToAdd = $organization->users->diff($team->users);
                                            $isAdmin = $organization->hasAdminAccess(auth()->user());
                                        @endphp
                                        <li class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900/80">
                                            {{-- Header strip: team name (editable inline) + delete.
                                                 Mirrors the sandy "MEMBERS" bar on the member directory
                                                 so both cards read as the same primitive. --}}
                                            <div class="border-b border-brand-ink/10 bg-brand-sand/35 px-4 py-2.5 dark:border-brand-mist/15 dark:bg-zinc-800/60">
                                                <div class="flex items-center justify-between gap-3">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Team') }}</p>
                                                        <input
                                                            type="text"
                                                            wire:model="teamNames.{{ $team->id }}"
                                                            wire:blur='promptSaveTeamNameOnBlur(@js($team->id))'
                                                            class="mt-0.5 w-full max-w-md border-0 bg-transparent p-0 text-sm font-semibold text-brand-ink focus:ring-0"
                                                            aria-label="{{ __('Team name') }}"
                                                        />
                                                        @error('teamNames.'.$team->id)
                                                            <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                                                        @enderror
                                                    </div>
                                                    @if ($isAdmin)
                                                        <button
                                                            type="button"
                                                            wire:click='promptDeleteTeam(@js($team->id))'
                                                            class="shrink-0 text-sm font-medium text-red-600 hover:text-red-700 hover:underline dark:text-red-400"
                                                        >{{ __('Delete') }}</button>
                                                    @endif
                                                </div>
                                            </div>

                                            {{-- Section header: member count --}}
                                            <div class="border-b border-brand-ink/10 bg-brand-sand/35 px-4 py-2.5 dark:border-brand-mist/15 dark:bg-zinc-800/60">
                                                <p class="text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ trans_choice(':count member|:count members', $team->users->count()) }}</p>
                                            </div>

                                            {{-- Member rows. Same shape as the member directory rows
                                                 (name · email · trailing chip) but the chip is the
                                                 remove action on team membership. --}}
                                            <ul class="divide-y divide-brand-ink/10 dark:divide-brand-mist/15">
                                                @forelse ($team->users as $member)
                                                    <li class="flex items-center justify-between gap-4 px-4 py-3.5 transition-colors hover:bg-brand-sand/25 dark:hover:bg-zinc-800/50">
                                                        <div class="min-w-0">
                                                            <span class="font-medium text-brand-ink">{{ $member->name }}</span>
                                                            <span class="mt-0.5 block text-sm text-brand-moss sm:mt-0 sm:ml-2 sm:inline">{{ $member->email }}</span>
                                                        </div>
                                                        @if ($isAdmin)
                                                            <button
                                                                type="button"
                                                                wire:click='promptRemoveTeamMember(@js($team->id), @js($member->id))'
                                                                class="shrink-0 text-xs font-medium text-red-600 hover:text-red-700 hover:underline dark:text-red-400"
                                                                aria-label="{{ __('Remove :name from team', ['name' => $member->name]) }}"
                                                            >{{ __('Remove') }}</button>
                                                        @endif
                                                    </li>
                                                @empty
                                                    <li class="px-4 py-4 text-sm text-brand-moss">{{ __('No members yet.') }}</li>
                                                @endforelse
                                            </ul>

                                            {{-- Footer: add-member control + notification channels link --}}
                                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/20 px-4 py-3 dark:border-brand-mist/15 dark:bg-zinc-800/40">
                                                @if ($isAdmin && $membersAvailableToAdd->isNotEmpty())
                                                    <div class="inline-flex items-center gap-2">
                                                        <select wire:model.live="addMemberSelected.{{ $team->id }}" class="rounded-lg border-brand-ink/15 bg-white py-1.5 text-xs dark:border-brand-mist/30 dark:bg-zinc-900">
                                                            <option value="">{{ __('Add member…') }}</option>
                                                            @foreach ($membersAvailableToAdd as $u)
                                                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                                                            @endforeach
                                                        </select>
                                                        <button type="button" wire:click='addTeamMember(@js($team->id))' class="inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                            {{ __('Add') }}
                                                        </button>
                                                    </div>
                                                @else
                                                    <span></span>
                                                @endif
                                                <a href="{{ route('teams.notification-channels', [$organization, $team]) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink">
                                                    {{ __('Team notification channels') }} →
                                                </a>
                                            </div>
                                            @error('team_'.$team->id)
                                                <p class="border-t border-red-200 bg-red-50/60 px-4 py-2 text-xs text-red-700">{{ $message }}</p>
                                            @enderror
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </section>
                </div>
            </x-organization-shell>
        </div>

    @if ($organization->hasAdminAccess(auth()->user()))
        <x-modal
            name="create-team-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="createTeam">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-rectangle-group class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Create team') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Name your team') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('Teams help you scope notifications and access. You can add organization members after the team is created.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="team_name_modal" :value="__('Team name')" />
                        <x-text-input
                            id="team_name_modal"
                            wire:model="team_name"
                            type="text"
                            class="mt-2 block w-full"
                            placeholder="{{ __('e.g. Platform, Customer success') }}"
                            required
                            maxlength="255"
                            autocomplete="off"
                        />
                        <x-input-error :messages="$errors->get('team_name')" class="mt-2" />
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeCreateTeamModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createTeam">
                        <span wire:loading.remove wire:target="createTeam" class="inline-flex items-center gap-2">
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Create team') }}
                        </span>
                        <span wire:loading wire:target="createTeam" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Creating…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Confirm modal must live in the Livewire view tree (not only a layout slot) so state updates and wire: targets bind reliably. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
