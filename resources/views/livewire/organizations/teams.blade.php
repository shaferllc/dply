<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="teams">
            <x-livewire-validation-errors />

            <div class="space-y-8">
                <div class="dply-card overflow-hidden">
                    <div class="p-6 sm:p-8">
                        <div class="max-w-3xl">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Teams') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Group members to scope servers, sites, and notifications. Organization members can be added to multiple teams.') }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Your teams') }}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Rename teams, manage membership, or open per-team notification channels.') }}</p>
                        </div>
                        <div class="lg:col-span-8 space-y-6 min-w-0">
                            @if ($organization->hasAdminAccess(auth()->user()))
                                <form wire:submit="createTeam" class="flex flex-wrap items-end gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/10 p-4">
                                    <div class="min-w-[12rem] flex-1">
                                        <label for="team_name" class="sr-only">{{ __('Team name') }}</label>
                                        <input
                                            type="text"
                                            id="team_name"
                                            wire:model="team_name"
                                            placeholder="{{ __('Team name') }}"
                                            required
                                            class="block w-full rounded-xl border border-brand-mist bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                        />
                                        @error('team_name')
                                            <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <x-primary-button type="submit" class="!text-sm">{{ __('Create team') }}</x-primary-button>
                                </form>
                            @endif

                            @if ($organization->teams->isEmpty())
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-6 py-12 text-center text-sm text-brand-moss">
                                    {{ __('No teams yet.') }}
                                </div>
                            @else
                                <ul class="space-y-4">
                                    @foreach ($organization->teams as $team)
                                        <li class="rounded-xl border border-brand-mist bg-white p-4 shadow-sm">
                                            <div class="flex justify-between items-start gap-4">
                                                <div class="min-w-0 flex-1">
                                                    <input
                                                        type="text"
                                                        wire:model="teamNames.{{ $team->id }}"
                                                        wire:blur="updateTeam({{ $team->id }})"
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
                                                        wire:click="openConfirmActionModal('deleteTeam', ['{{ $team->id }}'], @js(__('Delete team')), @js(__('Remove this team?')), @js(__('Delete')), true)"
                                                        class="shrink-0 text-sm font-medium text-red-600 hover:underline"
                                                    >{{ __('Delete') }}</button>
                                                @endif
                                            </div>
                                            <div class="mt-3 flex flex-wrap gap-2 items-center">
                                                @foreach ($team->users as $member)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs text-brand-ink">
                                                        {{ $member->name }}
                                                        @if ($organization->hasAdminAccess(auth()->user()))
                                                            <button type="button" wire:click="removeTeamMember({{ $team->id }}, {{ $member->id }})" class="text-brand-moss hover:text-red-600" aria-label="{{ __('Remove from team') }}">&times;</button>
                                                        @endif
                                                    </span>
                                                @endforeach
                                                @if ($organization->hasAdminAccess(auth()->user()) && $organization->users->isNotEmpty())
                                                    <div class="inline-flex flex-wrap items-center gap-1">
                                                        <select wire:model="addMemberSelected.{{ $team->id }}" class="rounded-lg border-brand-mist text-xs py-1">
                                                            <option value="">{{ __('Add member…') }}</option>
                                                            @foreach ($organization->users->diff($team->users) as $u)
                                                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                                                            @endforeach
                                                        </select>
                                                        <button type="button" wire:click="addTeamMember({{ $team->id }})" class="text-brand-sage hover:text-brand-ink text-xs font-medium">{{ __('Add') }}</button>
                                                    </div>
                                                    @error('team_'.$team->id)
                                                        <span class="w-full text-xs text-red-600">{{ $message }}</span>
                                                    @enderror
                                                @endif
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

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
