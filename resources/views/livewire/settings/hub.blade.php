<div>
    <x-breadcrumb-trail
        :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Settings'), 'href' => route('settings.profile'), 'icon' => 'cog-6-tooth'],
            ['label' => $section === 'servers' ? __('Servers & Sites') : __('Profile'), 'icon' => $section === 'servers' ? 'server' : 'user-circle'],
        ]"
        wrapper-class="mb-2"
    />

    <x-page-header
        :title="__('Settings')"
        :description="__('Profile stores personal preferences on this page. Servers & Sites covers organization defaults and team defaults (servers belong to teams).')"
        doc-route="docs.index"
        flush
    />

    <div class="border-b border-brand-mist/60 mb-6">
        <nav class="-mb-px flex gap-6" aria-label="Settings sections">
            <a
                href="{{ route('settings.profile') }}"
                wire:navigate
                @class([
                    'inline-flex items-center gap-2 border-b-2 py-3 text-sm font-medium transition-colors',
                    'border-brand-ink text-brand-ink' => request()->routeIs('settings.profile'),
                    'border-transparent text-brand-moss hover:text-brand-ink' => ! request()->routeIs('settings.profile'),
                ])
            >
                <x-heroicon-o-user-circle class="h-5 w-5 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Profile') }}
            </a>
            <a
                href="{{ route('settings.servers') }}"
                wire:navigate
                @class([
                    'inline-flex items-center gap-2 border-b-2 py-3 text-sm font-medium transition-colors',
                    'border-brand-ink text-brand-ink' => request()->routeIs('settings.servers'),
                    'border-transparent text-brand-moss hover:text-brand-ink' => ! request()->routeIs('settings.servers'),
                ])
            >
                <x-heroicon-o-server class="h-5 w-5 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Servers & Sites') }}
            </a>
        </nav>
    </div>

    @if ($section === 'profile')
        <form wire:submit="saveProfile" class="rounded-2xl border border-brand-mist/80 bg-white shadow-sm overflow-hidden">
            <button type="submit" class="sr-only">{{ __('Save settings') }}</button>
            <div class="lg:grid lg:grid-cols-12 lg:gap-10 p-6 lg:p-8">
                <div class="lg:col-span-4 mb-8 lg:mb-0">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Profile') }}</h2>
                    <p class="mt-1 text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Your account') }}</p>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Only you — not shared with your organization or teams.') }}</p>
                </div>
                <div class="lg:col-span-8 space-y-8 min-w-0">
                    <div class="space-y-5">
                        <label class="flex gap-3 cursor-pointer group">
                            <input type="checkbox" wire:model.boolean="ui.newsletter" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                            <span>
                                <span class="block text-sm font-medium text-brand-ink">{{ __('Receive newsletter') }}</span>
                                <span class="block text-sm text-brand-moss mt-0.5">{{ __('We will not send irrelevant spam — only product updates you can use.') }}</span>
                            </span>
                        </label>
                        <label class="flex gap-3 cursor-pointer group">
                            <input type="checkbox" wire:model.boolean="ui.keyboard_shortcuts" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                            <span>
                                <span class="block text-sm font-medium text-brand-ink">{{ __('Enable keyboard shortcuts') }}</span>
                                <span class="block text-sm text-brand-moss mt-0.5">{{ __('Turns keyboard shortcuts on or off in the app.') }}</span>
                            </span>
                        </label>
                        <label class="flex gap-3 cursor-pointer group">
                            <input type="checkbox" wire:model.boolean="ui.redirect_home_to_app" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                            <span>
                                <span class="block text-sm font-medium text-brand-ink">{{ __('Redirect to app when logged in') }}</span>
                                <span class="block text-sm text-brand-moss mt-0.5">{{ __('When you visit the marketing homepage while signed in, send you to the dashboard.') }}</span>
                            </span>
                        </label>
                        <label class="flex gap-3 cursor-pointer group">
                            <input type="checkbox" wire:model.boolean="ui.subscription_invoice_emails" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                            <span>
                                <span class="block text-sm font-medium text-brand-ink">{{ __('Receive invoice emails for subscriptions') }}</span>
                                <span class="block text-sm text-brand-moss mt-0.5">{{ __('When your organization moves from trial to Pro, include Stripe invoice PDFs in email.') }}</span>
                            </span>
                        </label>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-brand-ink">{{ __('Theme mode') }}</span>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Choose appearance or follow your system setting.') }}</p>
                        <div class="mt-3 inline-flex flex-wrap gap-1 rounded-xl border border-brand-mist bg-brand-cream/80 p-1">
                            @foreach (config('user_preferences.theme_options', []) as $opt)
                                <button
                                    type="button"
                                    wire:click="persistTheme('{{ $opt }}')"
                                    @class([
                                        'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-brand-ink text-brand-cream shadow-sm' => ($ui['theme'] ?? '') === $opt,
                                        'text-brand-moss hover:bg-brand-sand/50' => ($ui['theme'] ?? '') !== $opt,
                                    ])
                                >
                                    @if ($opt === 'light')
                                        <span class="inline-flex items-center gap-1.5">
                                            <x-heroicon-o-sun class="h-4 w-4" aria-hidden="true" />
                                            {{ __('Light') }}
                                        </span>
                                    @elseif ($opt === 'dark')
                                        <span class="inline-flex items-center gap-1.5">
                                            <x-heroicon-o-moon class="h-4 w-4" aria-hidden="true" />
                                            {{ __('Dark') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5">
                                            <x-heroicon-o-computer-desktop class="h-4 w-4" aria-hidden="true" />
                                            {{ __('System') }}
                                        </span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                        @error('ui.theme') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-brand-ink">{{ __('Navigation layout') }}</span>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Controls the Settings area: sidebar links on large screens, or a horizontal link row under the header.') }}</p>
                        <div class="mt-3 inline-flex flex-wrap gap-1 rounded-xl border border-brand-mist bg-brand-cream/80 p-1">
                            @foreach (config('user_preferences.navigation_layout_options', []) as $opt)
                                <button
                                    type="button"
                                    wire:click="persistNavigationLayout('{{ $opt }}')"
                                    @class([
                                        'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-brand-ink text-brand-cream shadow-sm' => ($ui['navigation_layout'] ?? '') === $opt,
                                        'text-brand-moss hover:bg-brand-sand/50' => ($ui['navigation_layout'] ?? '') !== $opt,
                                    ])
                                >
                                    @if ($opt === 'sidebar')
                                        <span class="inline-flex items-center gap-1.5">
                                            <x-heroicon-o-squares-2x2 class="h-4 w-4" aria-hidden="true" />
                                            {{ __('Sidebar') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5">
                                            <x-heroicon-o-bars-3 class="h-4 w-4" aria-hidden="true" />
                                            {{ __('Top') }}
                                        </span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                        @error('ui.navigation_layout') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="notification-position" class="block text-sm font-medium text-brand-ink">{{ __('Notification position') }}</label>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Where toast-style notifications appear on screen.') }}</p>
                        <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-stretch">
                            <select
                                id="notification-position"
                                wire:model="ui.notification_position"
                                class="block w-full min-w-0 max-w-md flex-1 rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            >
                                @foreach (config('user_preferences.notification_positions', []) as $value => $label)
                                    <option value="{{ $value }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                            {{-- Client-only preview: a Livewire action round-trip clears wire:dirty and hides the unsaved bar. --}}
                            <button
                                type="button"
                                data-notification-preview-message="{{ __('This is where notifications will appear.') }}"
                                onclick="window.dispatchEvent(new CustomEvent('toast', { detail: { message: this.dataset.notificationPreviewMessage, type: 'success', position: document.getElementById('notification-position').value } }))"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-mist bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm transition hover:bg-brand-cream focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage sm:self-start"
                            >
                                {{ __('Test') }}
                            </button>
                        </div>
                        @error('ui.notification_position') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </form>

        <x-unsaved-changes-bar
            :message="__('You have unsaved changes to your profile preferences.')"
            saveAction="saveProfile"
            discardAction="discardProfileUnsaved"
            :targets="$profileUnsavedTargets"
            :saveLabel="__('Save settings')"
        />
    @endif

    @if ($section === 'servers')
        <div class="space-y-8">
            <form wire:submit="saveProfileTimezone" class="rounded-2xl border border-brand-mist/80 bg-white shadow-sm overflow-hidden">
                <button type="submit" class="sr-only">{{ __('Save timezone') }}</button>
                <div class="lg:grid lg:grid-cols-12 lg:gap-10 p-6 lg:p-8">
                    <div class="lg:col-span-4 mb-8 lg:mb-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Your timezone') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Used for schedules, Insights quiet hours, and when applying timezone on new servers below.') }}</p>
                    </div>
                    <div class="lg:col-span-8 space-y-4 min-w-0">
                        <div>
                            <x-input-label for="hub-profile-timezone" :value="__('Timezone')" required />
                            <select
                                id="hub-profile-timezone"
                                wire:model="profileTimezone"
                                required
                                class="mt-2 block w-full max-w-md rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage max-h-48 sm:max-h-none"
                            >
                                @foreach ($this->timezones as $tz)
                                    <option value="{{ $tz }}">{{ $tz }}</option>
                                @endforeach
                            </select>
                            @error('profileTimezone') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </form>

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to your timezone.')"
                saveAction="saveProfileTimezone"
                discardAction="discardProfileTimezoneUnsaved"
                :targets="$profileTimezoneUnsavedTargets"
                :saveLabel="__('Save timezone')"
            />

            <form wire:submit="saveOrganizationServersSites" class="rounded-2xl border border-brand-mist/80 bg-white shadow-sm overflow-hidden">
                <button type="submit" class="sr-only">{{ __('Save organization settings') }}</button>
                <div class="lg:grid lg:grid-cols-12 lg:gap-10 p-6 lg:p-8">
                    <div class="lg:col-span-4 mb-8 lg:mb-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Organization') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Org-wide policies for email and new servers.') }}</p>
                        @if ($currentOrg)
                            <p class="mt-4 text-xs font-medium uppercase tracking-wider text-brand-mist">{{ __('Current organization') }}</p>
                            <p class="text-sm text-brand-ink font-medium">{{ $currentOrg->name }}</p>
                        @endif
                    </div>
                    <div class="lg:col-span-8 space-y-6 min-w-0">
                        @if (! $currentOrg)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                {{ __('Create or join an organization to configure these options.') }}
                            </div>
                        @elseif (! $canEditOrgPrefs)
                            <div class="rounded-lg border border-brand-mist bg-brand-cream px-4 py-3 text-sm text-brand-moss">
                                {{ __('Only organization admins can change organization defaults.') }}
                            </div>
                        @else
                            <div class="space-y-5">
                                <label class="flex gap-3 cursor-pointer group">
                                    <input type="checkbox" wire:model.boolean="organizationServerSite.email_server_passwords" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                    <span>
                                        <span class="block text-sm font-medium text-brand-ink">{{ __('Receive server passwords via email') }}</span>
                                        <span class="block text-sm text-brand-moss mt-0.5">{{ __('When off, retrieve credentials from each server’s settings in the app.') }}</span>
                                    </span>
                                </label>
                                <label class="flex gap-3 cursor-pointer group">
                                    <input type="checkbox" wire:model.boolean="organizationServerSite.set_timezone_on_new_servers" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                    <span>
                                        <span class="block text-sm font-medium text-brand-ink">{{ __('Set timezone on new servers') }}</span>
                                        <span class="block text-sm text-brand-moss mt-0.5">{{ __('Apply the timezone from above to new servers. (Currently: :tz)', ['tz' => $userTimezoneLabel]) }}</span>
                                    </span>
                                </label>
                            </div>
                        @endif
                    </div>
                </div>
            </form>

            <form wire:submit="saveOrganizationInsights" class="rounded-2xl border border-brand-mist/80 bg-white shadow-sm overflow-hidden">
                <button type="submit" class="sr-only">{{ __('Save Insights preferences') }}</button>
                <div class="lg:grid lg:grid-cols-12 lg:gap-10 p-6 lg:p-8">
                    <div class="lg:col-span-4 mb-8 lg:mb-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Insights') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Organization defaults for alert batching and quiet hours. Critical findings still notify immediately when channels are subscribed.') }}</p>
                    </div>
                    <div class="lg:col-span-8 space-y-6 min-w-0">
                        @if (! $currentOrg)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                {{ __('Create or join an organization to configure these options.') }}
                            </div>
                        @elseif (! $canEditOrgPrefs)
                            <div class="rounded-lg border border-brand-mist bg-brand-cream px-4 py-3 text-sm text-brand-moss">
                                {{ __('Only organization admins can change Insights preferences.') }}
                            </div>
                        @else
                            <div class="space-y-5">
                                <label class="flex gap-3 cursor-pointer group">
                                    <input type="checkbox" wire:model.boolean="organizationInsights.digest_non_critical" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                    <span>
                                        <span class="block text-sm font-medium text-brand-ink">{{ __('Digest non-critical findings') }}</span>
                                        <span class="block text-sm text-brand-moss mt-0.5">{{ __('Batch warning and info findings into email instead of immediate notifications. Critical stays immediate.') }}</span>
                                    </span>
                                </label>
                                <div class="max-w-md">
                                    <label for="org-insights-digest-frequency" class="block text-sm font-medium text-brand-ink">{{ __('Digest email frequency') }}</label>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('Daily runs at 08:00 app time; weekly runs Mondays at 08:15. Only applies while digest mode is enabled above.') }}</p>
                                    <select
                                        id="org-insights-digest-frequency"
                                        wire:model="organizationInsights.digest_frequency"
                                        class="mt-2 block w-full rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    >
                                        <option value="daily">{{ __('Daily') }}</option>
                                        <option value="weekly">{{ __('Weekly (Mondays)') }}</option>
                                    </select>
                                    @error('organizationInsights.digest_frequency') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <label class="flex gap-3 cursor-pointer group">
                                    <input type="checkbox" wire:model.boolean="organizationInsights.quiet_hours_enabled" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                    <span>
                                        <span class="block text-sm font-medium text-brand-ink">{{ __('Quiet hours for non-critical') }}</span>
                                        <span class="block text-sm text-brand-moss mt-0.5">{{ __('During the window below, suppress immediate non-critical insight alerts (digest still applies when enabled). Uses the app timezone (:tz).', ['tz' => config('app.timezone')]) }}</span>
                                    </span>
                                </label>
                                <div class="grid gap-4 sm:grid-cols-2 max-w-md">
                                    <div>
                                        <label for="org-insights-quiet-start" class="block text-sm font-medium text-brand-ink">{{ __('Quiet start (hour)') }}</label>
                                        <input
                                            id="org-insights-quiet-start"
                                            type="number"
                                            min="0"
                                            max="23"
                                            wire:model="organizationInsights.quiet_hours_start"
                                            class="mt-2 block w-full rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                        />
                                        @error('organizationInsights.quiet_hours_start') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label for="org-insights-quiet-end" class="block text-sm font-medium text-brand-ink">{{ __('Quiet end (hour)') }}</label>
                                        <input
                                            id="org-insights-quiet-end"
                                            type="number"
                                            min="0"
                                            max="23"
                                            wire:model="organizationInsights.quiet_hours_end"
                                            class="mt-2 block w-full rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                        />
                                        @error('organizationInsights.quiet_hours_end') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </form>

            <form wire:submit="saveTeamServersSites" class="rounded-2xl border border-brand-mist/80 bg-white shadow-sm overflow-hidden">
                <button type="submit" class="sr-only">{{ __('Save team settings') }}</button>
                <div class="lg:grid lg:grid-cols-12 lg:gap-10 p-6 lg:p-8">
                    <div class="lg:col-span-4 mb-8 lg:mb-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Team') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss">{{ __('List and creation defaults for servers and sites in the selected team.') }}</p>
                    </div>
                    <div class="lg:col-span-8 space-y-6 min-w-0">
                        @if (! $currentOrg)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                {{ __('Create or join an organization first.') }}
                            </div>
                        @elseif ($teams->isEmpty())
                            <div class="rounded-lg border border-brand-mist bg-brand-cream px-4 py-3 text-sm text-brand-moss">
                                {{ __('Add a team to this organization to configure team defaults.') }}
                            </div>
                        @else
                            <div>
                                <label for="settings-team" class="block text-sm font-medium text-brand-ink">{{ __('Team') }}</label>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Choose which team’s defaults you are editing.') }}</p>
                                <select
                                    id="settings-team"
                                    wire:model.live="selectedTeamId"
                                    class="mt-3 block w-full max-w-md rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                >
                                    @foreach ($teams as $team)
                                        <option value="{{ $team->id }}">{{ $team->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            @if (! $canEditTeamPrefs)
                                <div class="rounded-lg border border-brand-mist bg-brand-cream px-4 py-3 text-sm text-brand-moss">
                                    {{ __('Only team admins (or organization admins) can change team defaults.') }}
                                </div>
                            @else
                                <div class="space-y-5">
                                    <label class="flex gap-3 cursor-pointer group">
                                        <input type="checkbox" wire:model.boolean="teamServerSite.show_server_updates_in_list" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                        <span>
                                            <span class="block text-sm font-medium text-brand-ink">{{ __('Show server updates in list') }}</span>
                                            <span class="block text-sm text-brand-moss mt-0.5">{{ __('Surface pending updates in the server list when available.') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex gap-3 cursor-pointer group">
                                        <input type="checkbox" wire:model.boolean="teamServerSite.isolate_new_sites" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                        <span>
                                            <span class="block text-sm font-medium text-brand-ink">{{ __('Always use isolation for new sites') }}</span>
                                            <span class="block text-sm text-brand-moss mt-0.5">{{ __('Prefer isolated system users for new sites when the stack supports it.') }}</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="grid gap-6 sm:grid-cols-2">
                                    <div>
                                        <label for="team-default-server-sort" class="block text-sm font-medium text-brand-ink">{{ __('Default server sorting') }}</label>
                                        <select
                                            id="team-default-server-sort"
                                            wire:model="teamServerSite.default_server_sort"
                                            class="mt-2 block w-full rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                        >
                                            @foreach (config('user_preferences.server_sort_options', []) as $value => $label)
                                                <option value="{{ $value }}">{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                        @error('teamServerSite.default_server_sort') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label for="team-default-site-sort" class="block text-sm font-medium text-brand-ink">{{ __('Default site sorting') }}</label>
                                        <select
                                            id="team-default-site-sort"
                                            wire:model="teamServerSite.default_site_sort"
                                            class="mt-2 block w-full rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                        >
                                            @foreach (config('user_preferences.site_sort_options', []) as $value => $label)
                                                <option value="{{ $value }}">{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                        @error('teamServerSite.default_site_sort') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </form>

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
        </div>
    @endif

</div>
