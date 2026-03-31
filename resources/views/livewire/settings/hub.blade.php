<div>
    <nav class="mb-2 text-sm text-brand-mist" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}" class="hover:text-brand-ink" wire:navigate>{{ __('Dashboard') }}</a>
        <span class="mx-2" aria-hidden="true">/</span>
        <span class="text-brand-ink">{{ __('Settings') }}</span>
    </nav>

    <div class="mb-8">
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Settings') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-brand-moss">
            {{ __('Your profile tab stores personal preferences. Servers & Sites covers organization and firewall policies plus team defaults (servers belong to teams).') }}
        </p>
    </div>

    <div class="border-b border-brand-mist/60 mb-6">
        <nav class="-mb-px flex gap-6" aria-label="Settings sections">
            <button
                type="button"
                wire:click="$set('activeTab', 'profile')"
                @class([
                    'border-b-2 py-3 text-sm font-medium transition-colors',
                    'border-brand-ink text-brand-ink' => $activeTab === 'profile',
                    'border-transparent text-brand-moss hover:text-brand-ink' => $activeTab !== 'profile',
                ])
            >
                {{ __('Profile') }}
            </button>
            <button
                type="button"
                wire:click="$set('activeTab', 'servers')"
                @class([
                    'border-b-2 py-3 text-sm font-medium transition-colors',
                    'border-brand-ink text-brand-ink' => $activeTab === 'servers',
                    'border-transparent text-brand-moss hover:text-brand-ink' => $activeTab !== 'servers',
                ])
            >
                {{ __('Servers & Sites') }}
            </button>
        </nav>
    </div>

    @if ($activeTab === 'profile')
        <form wire:submit="saveProfile" class="rounded-2xl border border-brand-mist/80 bg-white shadow-sm overflow-hidden">
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
                                <span class="block text-sm text-brand-moss mt-0.5">{{ __('When your organization has an active subscription, include Stripe invoice PDFs in email.') }}</span>
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
                                    wire:click="$set('ui.theme', '{{ $opt }}')"
                                    @class([
                                        'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-brand-ink text-brand-cream shadow-sm' => ($ui['theme'] ?? '') === $opt,
                                        'text-brand-moss hover:bg-brand-sand/50' => ($ui['theme'] ?? '') !== $opt,
                                    ])
                                >
                                    @if ($opt === 'light')
                                        <span class="inline-flex items-center gap-1.5">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/></svg>
                                            {{ __('Light') }}
                                        </span>
                                    @elseif ($opt === 'dark')
                                        <span class="inline-flex items-center gap-1.5">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z"/></svg>
                                            {{ __('Dark') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 00-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25"/></svg>
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
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Sidebar or top navigation (where the shell supports it).') }}</p>
                        <div class="mt-3 inline-flex flex-wrap gap-1 rounded-xl border border-brand-mist bg-brand-cream/80 p-1">
                            @foreach (config('user_preferences.navigation_layout_options', []) as $opt)
                                <button
                                    type="button"
                                    wire:click="$set('ui.navigation_layout', '{{ $opt }}')"
                                    @class([
                                        'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-brand-ink text-brand-cream shadow-sm' => ($ui['navigation_layout'] ?? '') === $opt,
                                        'text-brand-moss hover:bg-brand-sand/50' => ($ui['navigation_layout'] ?? '') !== $opt,
                                    ])
                                >
                                    @if ($opt === 'sidebar')
                                        <span class="inline-flex items-center gap-1.5">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                                            {{ __('Sidebar') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg>
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
                        <select
                            id="notification-position"
                            wire:model="ui.notification_position"
                            class="mt-3 block w-full max-w-md rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        >
                            @foreach (config('user_preferences.notification_positions', []) as $value => $label)
                                <option value="{{ $value }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                        @error('ui.notification_position') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div class="flex justify-end border-t border-brand-mist/60 bg-brand-sand/30 px-6 py-4">
                <button type="submit" class="inline-flex items-center rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-ink/90 focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2">
                    {{ __('Save settings') }}
                </button>
            </div>
        </form>
    @endif

    @if ($activeTab === 'servers')
        <div class="space-y-8">
            <form wire:submit="saveOrganizationServersSites" class="rounded-2xl border border-brand-mist/80 bg-white shadow-sm overflow-hidden">
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
                                        <span class="block text-sm text-brand-moss mt-0.5">{{ __('Apply your profile timezone to new servers. (Currently: :tz)', ['tz' => $userTimezoneLabel]) }}</span>
                                    </span>
                                </label>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="flex justify-end border-t border-brand-mist/60 bg-brand-sand/30 px-6 py-4">
                    <button
                        type="submit"
                        @disabled(! $currentOrg || ! $canEditOrgPrefs)
                        class="inline-flex items-center rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-ink/90 focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('Save organization settings') }}
                    </button>
                </div>
            </form>

            <form wire:submit="saveOrganizationFirewall" class="rounded-2xl border border-brand-mist/80 bg-white shadow-sm overflow-hidden">
                <div class="lg:grid lg:grid-cols-12 lg:gap-10 p-6 lg:p-8">
                    <div class="lg:col-span-4 mb-8 lg:mb-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Firewall') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Organization-wide options for server firewall workflows (UFW).') }}</p>
                    </div>
                    <div class="lg:col-span-8 space-y-6 min-w-0">
                        @if (! $currentOrg)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                {{ __('Create or join an organization to configure these options.') }}
                            </div>
                        @elseif (! $canEditOrgPrefs)
                            <div class="rounded-lg border border-brand-mist bg-brand-cream px-4 py-3 text-sm text-brand-moss">
                                {{ __('Only organization admins can change firewall policies.') }}
                            </div>
                        @else
                            <div class="space-y-5">
                                <label class="flex gap-3 cursor-pointer group">
                                    <input type="checkbox" wire:model.boolean="organizationFirewall.require_second_approval" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                    <span>
                                        <span class="block text-sm font-medium text-brand-ink">{{ __('Require two people to apply firewall rules') }}</span>
                                        <span class="block text-sm text-brand-moss mt-0.5">{{ __('After one member saves changes, a different member must click apply in the server firewall workspace before rules are pushed to the host.') }}</span>
                                    </span>
                                </label>
                                <label class="flex gap-3 cursor-pointer group">
                                    <input type="checkbox" wire:model.boolean="organizationFirewall.notify_drift_webhook" class="mt-1 rounded border-brand-mist text-brand-ink focus:ring-brand-sage" />
                                    <span>
                                        <span class="block text-sm font-medium text-brand-ink">{{ __('Notify integration webhooks on UFW drift') }}</span>
                                        <span class="block text-sm text-brand-moss mt-0.5">{{ __('When drift is detected in the firewall workspace, send a webhook if your organization has outbound integrations configured.') }}</span>
                                    </span>
                                </label>
                                <div>
                                    <label for="org-firewall-synthetic-url" class="block text-sm font-medium text-brand-ink">{{ __('Synthetic probe URL (optional)') }}</label>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('After a firewall apply, optionally GET this URL from the control plane to verify reachability (for example a health check). Leave blank to disable.') }}</p>
                                    <input
                                        id="org-firewall-synthetic-url"
                                        type="text"
                                        inputmode="url"
                                        autocomplete="off"
                                        placeholder="https://"
                                        wire:model="organizationFirewall.synthetic_probe_url"
                                        class="mt-3 block w-full max-w-xl rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    />
                                    @error('organizationFirewall.synthetic_probe_url') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="flex justify-end border-t border-brand-mist/60 bg-brand-sand/30 px-6 py-4">
                    <button
                        type="submit"
                        @disabled(! $currentOrg || ! $canEditOrgPrefs)
                        class="inline-flex items-center rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-ink/90 focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('Save firewall settings') }}
                    </button>
                </div>
            </form>

            <form wire:submit="saveOrganizationInsights" class="rounded-2xl border border-brand-mist/80 bg-white shadow-sm overflow-hidden">
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
                <div class="flex justify-end border-t border-brand-mist/60 bg-brand-sand/30 px-6 py-4">
                    <button
                        type="submit"
                        @disabled(! $currentOrg || ! $canEditOrgPrefs)
                        class="inline-flex items-center rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-ink/90 focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('Save Insights preferences') }}
                    </button>
                </div>
            </form>

            <form wire:submit="saveTeamServersSites" class="rounded-2xl border border-brand-mist/80 bg-white shadow-sm overflow-hidden">
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
                <div class="flex justify-end border-t border-brand-mist/60 bg-brand-sand/30 px-6 py-4">
                    <button
                        type="submit"
                        @disabled(! $currentOrg || $teams->isEmpty() || ! $canEditTeamPrefs)
                        class="inline-flex items-center rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-ink/90 focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('Save team settings') }}
                    </button>
                </div>
            </form>
        </div>
    @endif

    <section class="mt-12">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-brand-mist mb-4">{{ __('More settings') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <a href="{{ route('profile.edit') }}" class="block rounded-2xl border border-brand-mist/80 bg-white p-5 shadow-sm transition hover:border-brand-sage/50" wire:navigate>
                <h3 class="font-medium text-brand-ink">{{ __('Profile details') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Name, email, billing profile, and account deletion.') }}</p>
            </a>
            <a href="{{ route('profile.referrals') }}" class="block rounded-2xl border border-brand-mist/80 bg-white p-5 shadow-sm transition hover:border-brand-sage/50" wire:navigate>
                <h3 class="font-medium text-brand-ink">{{ __('Referrals') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Share your link and track sign-ups.') }}</p>
            </a>
            <a href="{{ route('profile.security') }}" class="block rounded-2xl border border-brand-mist/80 bg-white p-5 shadow-sm transition hover:border-brand-sage/50" wire:navigate>
                <h3 class="font-medium text-brand-ink">{{ __('Security') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Password and two-factor authentication.') }}</p>
            </a>
            <a href="{{ route('organizations.index') }}" class="block rounded-2xl border border-brand-mist/80 bg-white p-5 shadow-sm transition hover:border-brand-sage/50" wire:navigate>
                <h3 class="font-medium text-brand-ink">{{ __('Organizations') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Members, roles, and plan usage.') }}</p>
            </a>
            <a href="{{ route('profile.source-control') }}" class="block rounded-2xl border border-brand-mist/80 bg-white p-5 shadow-sm transition hover:border-brand-sage/50" wire:navigate>
                <h3 class="font-medium text-brand-ink">{{ __('Source control') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Git providers (GitHub, GitLab, Bitbucket).') }}</p>
            </a>
            <a href="{{ route('profile.api-keys') }}" class="block rounded-2xl border border-brand-mist/80 bg-white p-5 shadow-sm transition hover:border-brand-sage/50" wire:navigate>
                <h3 class="font-medium text-brand-ink">{{ __('API keys') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Personal access tokens for the HTTP API.') }}</p>
            </a>
            @if ($currentOrg)
                <a href="{{ route('credentials.index') }}" class="block rounded-2xl border border-brand-mist/80 bg-white p-5 shadow-sm transition hover:border-brand-sage/50" wire:navigate>
                    <h3 class="font-medium text-brand-ink">{{ __('Server providers') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Cloud API tokens to create and manage infrastructure.') }}</p>
                </a>
                @if ($currentOrg->hasAdminAccess(auth()->user()))
                    <a href="{{ route('subscription.show', $currentOrg) }}" class="block rounded-2xl border border-brand-mist/80 bg-white p-5 shadow-sm transition hover:border-brand-sage/50" wire:navigate>
                        <h3 class="font-medium text-brand-ink">{{ __('Subscription') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Plan, payment method, and Stripe Customer Portal.') }}</p>
                    </a>
                    <a href="{{ route('billing.invoices', $currentOrg) }}" class="block rounded-2xl border border-brand-mist/80 bg-white p-5 shadow-sm transition hover:border-brand-sage/50" wire:navigate>
                        <h3 class="font-medium text-brand-ink">{{ __('Invoices') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Search, sort, and open PDFs from Stripe.') }}</p>
                    </a>
                @endif
            @endif
        </div>
    </section>

    <section class="mt-10 rounded-2xl border border-brand-mist/80 bg-brand-sand/40 p-6">
        <h2 class="text-sm font-semibold text-brand-ink">{{ __('What Dply does not manage') }}</h2>
        <ul class="mt-3 space-y-2 text-sm text-brand-moss list-disc list-inside">
            <li>
                <a href="{{ route('backups.databases') }}" class="font-medium text-brand-sage underline underline-offset-2 hover:text-brand-ink" wire:navigate>{{ __('Backups') }}</a>
                — {{ __('database and file backup plans for the current organization; use Storage destinations for S3 and other targets.') }}
            </li>
            <li>
                @if ($currentOrg)
                    <a href="{{ route('organizations.webserver-templates', $currentOrg) }}" class="font-medium text-brand-sage underline underline-offset-2 hover:text-brand-ink" wire:navigate>{{ __('Webserver templates') }}</a>
                @else
                    <span class="font-medium text-brand-ink">{{ __('Webserver templates') }}</span>
                @endif
                — {{ __('Save default Nginx :code blocks per organization; apply to sites from the site screen when wired.', ['code' => 'server']) }}
            </li>
            <li><span class="font-medium text-brand-ink">{{ __('SSH keys') }}</span> — <a href="{{ route('profile.ssh-keys') }}" class="font-medium text-brand-sage underline underline-offset-2 hover:text-brand-ink" wire:navigate>{{ __('Save keys on your account') }}</a> {{ __('and deploy to servers, or add keys on each server’s page.') }}</li>
        </ul>
    </section>

    <section class="mt-10 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-brand-mist">{{ __('Operational trust') }}</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Recovery paths') }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ __('Keep backups, storage destinations, and restore notes current before the next production incident. Settings link you into those workflows, while projects and server pages carry them into operations.') }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Governed changes') }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ __('Firewall approvals, notification routing, and org defaults help teams make changes with clearer ownership and less hidden machine state.') }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Team-wide visibility') }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ __('Preferences here shape how the wider workspace behaves, but the goal is simple: fewer surprises when someone else has to deploy, recover, or audit the stack.') }}</p>
            </div>
        </div>
    </section>
</div>
