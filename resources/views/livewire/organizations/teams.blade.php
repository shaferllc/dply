<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="teams">
            <x-livewire-validation-errors />

            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Teams'), 'icon' => 'rectangle-group'],
            ]" />

            <div class="space-y-8">
                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-6 lg:gap-10 lg:items-center p-6 sm:p-8">
                        <div class="lg:col-span-5 xl:col-span-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Collaboration') }}</p>
                            <h2 class="mt-2 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Teams') }}</h2>
                            <p class="mt-3 max-w-xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Group members to scope servers, sites, and notifications. Organization members can be added to multiple teams.') }}
                            </p>
                        </div>
                        <div class="lg:col-span-7 xl:col-span-8">
                            <div class="flex flex-col gap-4 sm:ml-auto sm:max-w-lg lg:max-w-none">
                                <div class="flex flex-wrap items-center justify-end gap-2 rounded-2xl p-2 sm:p-2.5 ">
                                    <a
                                        href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}"
                                        wire:navigate
                                        class="inline-flex items-center gap-2 rounded-xl border border-transparent bg-white/90 px-3 py-2 text-sm font-medium text-brand-ink shadow-sm ring-1 ring-brand-ink/10 transition hover:bg-white hover:ring-brand-sage/35 dark:bg-zinc-900/80 dark:ring-brand-mist/20 dark:hover:bg-zinc-900"
                                    >
                                        <x-heroicon-o-queue-list class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                        {{ __('Roles & limits') }}
                                    </a>
                                    <a
                                        href="{{ route('docs.index') }}"
                                        wire:navigate
                                        class="inline-flex items-center gap-2 rounded-xl border border-transparent bg-white/90 px-3 py-2 text-sm font-medium text-brand-ink shadow-sm ring-1 ring-brand-ink/10 transition hover:bg-white hover:ring-brand-sage/35 dark:bg-zinc-900/80 dark:ring-brand-mist/20 dark:hover:bg-zinc-900"
                                    >
                                        <x-heroicon-o-book-open class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                        {{ __('Documentation') }}
                                    </a>
                                </div>
                                <div class="flex justify-end">
                                    <div class="inline-flex max-w-full items-center gap-2 rounded-full border border-brand-ink/10 bg-brand-sand/40 px-3 py-1.5 text-xs text-brand-ink dark:border-brand-mist/20 dark:bg-zinc-800/60">
                                        <x-heroicon-o-building-office-2 class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                        <span class="min-w-0 truncate font-medium" title="{{ $organization->name }}">{{ $organization->name }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-6 lg:gap-10 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Team directory') }}</p>
                            <h3 class="mt-2 text-lg font-semibold text-brand-ink">{{ __('Your teams') }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ __('Rename teams, manage membership, or open per-team notification channels.') }}</p>
                            <p class="mt-4">
                                <a href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-sage underline decoration-brand-sage/35 underline-offset-2 transition hover:text-brand-ink hover:decoration-brand-ink/30">{{ __('Roles & limits') }} <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden="true" /></a>
                            </p>
                        </div>
                        <div class="lg:col-span-8 min-w-0 space-y-5">
                            @if ($organization->hasAdminAccess(auth()->user()))
                                <div class="flex justify-end">
                                    <x-primary-button type="button" wire:click="openCreateTeamModal">
                                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Create team') }}
                                    </x-primary-button>
                                </div>
                            @endif

                            @if ($organization->teams->isEmpty())
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-6 py-12 text-center text-sm text-brand-moss dark:border-brand-mist/20 dark:bg-zinc-800/40">
                                    {{ __('No teams yet.') }}
                                </div>
                            @else
                                <ul class="space-y-4">
                                    @foreach ($organization->teams as $team)
                                        @php
                                            $membersAvailableToAdd = $organization->users->diff($team->users);
                                        @endphp
                                        <li class="rounded-xl border border-brand-mist bg-white p-4 shadow-sm dark:border-brand-mist/25 dark:bg-zinc-900/80">
                                            <div class="flex justify-between items-start gap-4">
                                                <div class="min-w-0 flex-1">
                                                    <input
                                                        type="text"
                                                        wire:model="teamNames.{{ $team->id }}"
                                                        wire:blur='promptSaveTeamNameOnBlur(@js($team->id))'
                                                        class="w-full max-w-md border-0 border-b border-transparent bg-transparent font-medium text-brand-ink hover:border-brand-mist focus:border-brand-forest focus:ring-0 text-sm p-0"
                                                    />
                                                    @error('teamNames.'.$team->id)
                                                        <span class="block text-xs text-red-600">{{ $message }}</span>
                                                    @enderror
                                                    <p class="text-brand-moss text-sm mt-1">{{ trans_choice(':count member|:count members', $team->users->count()) }}</p>
                                                    <p class="mt-2">
                                                        <a href="{{ route('teams.notification-channels', [$organization, $team]) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('Team notification channels') }} →</a>
                                                    </p>
                                                </div>
                                                @if ($organization->hasAdminAccess(auth()->user()))
                                                    <button
                                                        type="button"
                                                        wire:click='promptDeleteTeam(@js($team->id))'
                                                        class="shrink-0 text-sm font-medium text-red-600 hover:underline dark:text-red-400"
                                                    >{{ __('Delete') }}</button>
                                                @endif
                                            </div>
                                            <div class="mt-3 flex flex-wrap gap-2 items-center">
                                                @foreach ($team->users as $member)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs text-brand-ink dark:bg-zinc-800 dark:text-brand-mist">
                                                        {{ $member->name }}
                                                        @if ($organization->hasAdminAccess(auth()->user()))
                                                            <button type="button" wire:click='promptRemoveTeamMember(@js($team->id), @js($member->id))' class="text-brand-moss hover:text-red-600" aria-label="{{ __('Remove from team') }}">&times;</button>
                                                        @endif
                                                    </span>
                                                @endforeach
                                                @if ($organization->hasAdminAccess(auth()->user()) && $membersAvailableToAdd->isNotEmpty())
                                                    <div class="inline-flex flex-wrap items-center gap-1">
                                                        <select wire:model.live="addMemberSelected.{{ $team->id }}" class="rounded-lg border border-brand-mist bg-white text-xs py-1 dark:border-brand-mist/30 dark:bg-zinc-900">
                                                            <option value="">{{ __('Add member…') }}</option>
                                                            @foreach ($membersAvailableToAdd as $u)
                                                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                                                            @endforeach
                                                        </select>
                                                        <button type="button" wire:click='addTeamMember(@js($team->id))' class="text-brand-sage hover:text-brand-ink text-xs font-medium">{{ __('Add') }}</button>
                                                    </div>
                                                @endif
                                                @error('team_'.$team->id)
                                                    <span class="w-full text-xs text-red-600">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </x-organization-shell>
    </div>

    <x-modal
        name="save-team-name-modal"
        :show="false"
        maxWidth="md"
        overlayClass="bg-brand-ink/30"
        panelClass="dply-modal-panel overflow-hidden shadow-xl"
        focusable
    >
        @php
            $pendingRenameTeam = $saveTeamNameModalTeamId
                ? $organization->teams->firstWhere('id', $saveTeamNameModalTeamId)
                : null;
            $pendingRenameNewName = $saveTeamNameModalTeamId
                ? trim((string) ($teamNames[$saveTeamNameModalTeamId] ?? ''))
                : '';
        @endphp
        <div class="border-b border-brand-ink/10 px-6 py-5 dark:border-brand-mist/20">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Team name') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Save changes?') }}</h2>
            @if ($pendingRenameTeam && $pendingRenameNewName !== '')
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('Change this team’s name from “:from” to “:to”?', ['from' => $pendingRenameTeam->name, 'to' => $pendingRenameNewName]) }}
                </p>
            @endif
        </div>

        <div class="px-6 py-5">
            @if ($saveTeamNameModalTeamId)
                <x-input-error :messages="$errors->get('teamNames.'.$saveTeamNameModalTeamId)" />
            @endif
        </div>

        <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4 dark:border-brand-mist/20">
            <x-secondary-button type="button" wire:click="cancelSaveTeamName">
                {{ __('Discard') }}
            </x-secondary-button>
            <x-primary-button type="button" wire:click="confirmSaveTeamName" wire:loading.attr="disabled" wire:target="confirmSaveTeamName">
                <span wire:loading.remove wire:target="confirmSaveTeamName">{{ __('Save name') }}</span>
                <span wire:loading wire:target="confirmSaveTeamName" class="inline-flex items-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Saving…') }}
                </span>
            </x-primary-button>
        </div>
    </x-modal>

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
                <div class="border-b border-brand-ink/10 px-6 py-5 dark:border-brand-mist/20">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Create team') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Name your team') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-brand-moss">
                        {{ __('Teams help you scope notifications and access. You can add organization members after the team is created.') }}
                    </p>
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

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4 dark:border-brand-mist/20">
                    <x-secondary-button type="button" wire:click="closeCreateTeamModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createTeam">
                        <span wire:loading.remove wire:target="createTeam">{{ __('Create team') }}</span>
                        <span wire:loading wire:target="createTeam" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" />
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
