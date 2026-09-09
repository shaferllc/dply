{{-- Servers & sites: the team defaults block. --}}
            {{-- Team defaults --}}
            <div>
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-rectangle-group"
                    :title="__('Team defaults')"
                    :note="__('List and creation defaults for servers and sites in the selected team.')"
                />
                <form wire:submit="saveTeamServersSites" class="px-3 py-2.5 sm:px-4">
                    <button type="submit" class="sr-only">{{ __('Save team settings') }}</button>
                    @if (! $currentOrg)
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                            {{ __('Create or join an organization first.') }}
                        </div>
                    @elseif ($teams->isEmpty())
                        <div class="rounded-md border border-brand-ink/10 bg-brand-cream/40 px-2.5 py-1.5 text-xs text-brand-moss">
                            {{ __('Add a team to this organization to configure team defaults.') }}
                        </div>
                    @else
                        <div class="space-y-2.5">
                            <div>
                                <label for="settings-team" class="block text-xs font-semibold text-brand-ink">{{ __('Team') }}</label>
                                <select
                                    id="settings-team"
                                    wire:model.live="selectedTeamId"
                                    class="mt-1 block w-full max-w-md rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                >
                                    @foreach ($teams as $team)
                                        <option value="{{ $team->id }}">{{ $team->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Choose which team\'s defaults you\'re editing.') }}</p>
                            </div>

                            @if (! $canEditTeamPrefs)
                                <div class="rounded-md border border-brand-ink/10 bg-brand-cream/40 px-2.5 py-1.5 text-xs text-brand-moss">
                                    {{ __('Only team admins (or organization admins) can change team defaults.') }}
                                </div>
                            @else
                                <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-lg border border-brand-ink/10">
                                    <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                        <input type="checkbox" wire:model.boolean="teamServerSite.show_server_updates_in_list" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                        <span class="min-w-0 flex-1">
                                            <span class="text-sm font-medium text-brand-ink">{{ __('Show server updates in list') }}</span>
                                            <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Surface pending updates in the server list when available.') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                        <input type="checkbox" wire:model.boolean="teamServerSite.isolate_new_sites" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                        <span class="min-w-0 flex-1">
                                            <span class="text-sm font-medium text-brand-ink">{{ __('Always use isolation for new sites') }}</span>
                                            <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Prefer isolated system users for new sites when the stack supports it.') }}</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="grid gap-2.5 sm:grid-cols-2">
                                    <div>
                                        <label for="team-default-server-sort" class="block text-xs font-semibold text-brand-ink">{{ __('Default server sort') }}</label>
                                        <select
                                            id="team-default-server-sort"
                                            wire:model="teamServerSite.default_server_sort"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                        >
                                            @foreach (config('user_preferences.server_sort_options', []) as $value => $label)
                                                <option value="{{ $value }}">{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                        @error('teamServerSite.default_server_sort') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label for="team-default-site-sort" class="block text-xs font-semibold text-brand-ink">{{ __('Default site sort') }}</label>
                                        <select
                                            id="team-default-site-sort"
                                            wire:model="teamServerSite.default_site_sort"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                        >
                                            @foreach (config('user_preferences.site_sort_options', []) as $value => $label)
                                                <option value="{{ $value }}">{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                        @error('teamServerSite.default_site_sort') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </form>
            </div>

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to organization defaults.')"
                saveAction="saveOrganizationServersSites"
                discardAction="discardOrganizationServersSitesUnsaved"
                :targets="$organizationServerSiteUnsavedTargets"
                :save-disabled="! $currentOrg || ! $canEditOrgPrefs"
                :saveLabel="__('Save organization settings')"
            />

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to Insights preferences.')"
                saveAction="saveOrganizationInsights"
                discardAction="discardOrganizationInsightsUnsaved"
                :targets="$organizationInsightsUnsavedTargets"
                :save-disabled="! $currentOrg || ! $canEditOrgPrefs"
                :saveLabel="__('Save Insights preferences')"
            />

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to team defaults.')"
                saveAction="saveTeamServersSites"
                discardAction="discardTeamServersSitesUnsaved"
                :targets="$teamServersSitesUnsavedTargets"
                :save-disabled="! $currentOrg || $teams->isEmpty() || ! $canEditTeamPrefs"
                :saveLabel="__('Save team settings')"
            />
