<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="overview">
            <div>
                <x-page-header
                    :eyebrow="__('Organization overview')"
                    :title="$organization->name"
                    :description="__('Use this page for a quick snapshot of plan usage, people, and recent organization activity. Detailed billing, notifications, provider credentials, and templates each have their own focused page in the organization sidebar.')"
                >
                    <x-slot name="actions">
                            @if ($organization->hasAdminAccess(auth()->user()))
                                <a href="{{ route('billing.show', $organization) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">{{ __('Billing & plan') }}</a>
                            @endif
                            @can('viewNotificationChannels', $organization)
                                <a href="{{ route('organizations.notification-channels', $organization) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">{{ __('Notification channels') }}</a>
                            @endcan
                            @can('viewAny', \App\Models\ProviderCredential::class)
                                <a href="{{ route('organizations.credentials', $organization) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">{{ __('Server providers') }}</a>
                            @endcan
                    </x-slot>
                </x-page-header>

                @if ($new_token_plaintext)
                    <x-alert tone="warning" class="mb-4">
                        <p class="mb-1 font-medium text-amber-900">{{ __('API token created: :name', ['name' => $new_token_name]) }}</p>
                        <p class="mb-2 text-sm text-amber-800">{{ __("Copy this token now. It won't be shown again.") }}</p>
                        <code class="block break-all rounded border border-amber-200 bg-white p-3 text-sm select-all">{{ $new_token_plaintext }}</code>
                        <button type="button" wire:click="clearNewToken" class="mt-2 text-sm text-amber-800 underline">{{ __('Dismiss') }}</button>
                    </x-alert>
                @endif

                <div class="space-y-8">
                    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <x-stat-card
                            :label="__('Plan')"
                            :value="$organization->planTierLabel()"
                            :meta="__('Trial limits and billing apply to the whole organization.')"
                        />
                        <x-stat-card :label="__('Infrastructure')" :value="$organization->servers_count.' '.Str::plural('server', $organization->servers_count)">
                            <span class="text-sm text-brand-moss">
                                {{ $organization->sites_count }} {{ Str::plural('site', $organization->sites_count) }}
                                @if ($organization->maxServers() < PHP_INT_MAX || $organization->maxSites() < PHP_INT_MAX)
                                    · {{ __('tracked against trial or plan limits') }}
                                @endif
                            </span>
                        </x-stat-card>
                        <x-stat-card
                            :label="__('People')"
                            :value="$organization->users->count().' '.Str::plural('member', $organization->users->count())"
                            :meta="$organization->teams->count().' '.Str::plural('team', $organization->teams->count()).' · '.$organization->invitations->count().' '.Str::plural('pending invite', $organization->invitations->count())"
                        />
                        <x-stat-card
                            :label="__('Automation')"
                            :value="$organization->apiTokens->count().' '.Str::plural('API token', $organization->apiTokens->count())"
                            :meta="$organization->notificationWebhookDestinations->count().' '.Str::plural('webhook destination', $organization->notificationWebhookDestinations->count())"
                        />
                    </section>

                    <section class="grid gap-8 xl:grid-cols-[1.45fr,1fr]">
                        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-6 py-4">
                                <div>
                                    <h3 class="font-medium text-slate-900">{{ __('Members') }}</h3>
                                    <p class="mt-1 text-sm text-slate-500">{{ __('See who has access, what role they have, and who is still pending.') }}</p>
                                </div>
                                @if ($organization->hasAdminAccess(auth()->user()))
                                    <form wire:submit="inviteMember" class="flex flex-wrap items-end gap-2">
                                        <div>
                                            <label for="invite_email" class="sr-only">Email</label>
                                            <input type="email" id="invite_email" wire:model="invite_email" placeholder="Email to invite" required class="rounded-md border-slate-300 shadow-sm text-sm">
                                            @error('invite_email')
                                                <span class="text-red-600 text-xs">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div>
                                            <label for="invite_role" class="sr-only">Role</label>
                                            <select id="invite_role" wire:model="invite_role" class="rounded-md border-slate-300 shadow-sm text-sm">
                                                <option value="member">Member</option>
                                                <option value="admin">Admin</option>
                                                <option value="deployer">Deployer</option>
                                            </select>
                                        </div>
                                        <x-primary-button type="submit" class="!text-sm">{{ __('Invite') }}</x-primary-button>
                                    </form>
                                @endif
                            </div>
                            @if ($organization->invitations->isNotEmpty())
                                <div class="border-b border-slate-100 bg-slate-50 px-6 py-3">
                                    <p class="mb-2 text-xs font-medium uppercase text-slate-500">{{ __('Pending invitations') }}</p>
                                    <ul class="space-y-1">
                                        @foreach ($organization->invitations as $inv)
                                            <li class="flex items-center justify-between text-sm">
                                                <span>{{ $inv->email }} ({{ $inv->role }})</span>
                                                @if ($organization->hasAdminAccess(auth()->user()))
                                                    <button type="button" wire:click="openConfirmActionModal('cancelInvitation', ['{{ $inv->id }}'], @js(__('Cancel invitation')), @js(__('Cancel this invitation?')), @js(__('Cancel invitation')), true)" class="text-red-600 hover:underline">{{ __('Cancel') }}</button>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <ul class="divide-y divide-slate-200">
                                @foreach ($organization->users as $user)
                                    <li class="flex items-center justify-between gap-4 px-6 py-3">
                                        <div class="min-w-0">
                                            <span class="font-medium text-slate-900">{{ $user->name }}</span>
                                            <span class="ml-2 text-sm text-slate-500">{{ $user->email }}</span>
                                        </div>
                                        <span class="shrink-0 text-xs font-medium uppercase text-slate-600">{{ $user->pivot->role }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </section>

                        <section class="space-y-6">
                            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                                <div class="border-b border-slate-200 px-6 py-4">
                                    <h3 class="font-medium text-slate-900">{{ __('Plan & usage') }}</h3>
                                    <p class="mt-1 text-sm text-slate-500">{{ __('A quick read on how close the organization is to trial limits or Pro usage.') }}</p>
                                </div>
                                <div class="space-y-4 px-6 py-4 text-sm">
                                    <div class="rounded-xl border border-slate-100 bg-slate-50/80 p-4">
                                        <dt class="font-medium text-slate-700">{{ __('Servers') }}</dt>
                                        <dd class="mt-1 text-slate-900">
                                            <span class="font-semibold tabular-nums">{{ $organization->servers_count }}</span>
                                            @if ($organization->maxServers() >= PHP_INT_MAX)
                                                <span class="text-slate-600">{{ __('(unlimited)') }}</span>
                                            @else
                                                <span class="text-slate-600">{{ __('of :count', ['count' => $organization->maxServersDisplay()]) }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                    <div class="rounded-xl border border-slate-100 bg-slate-50/80 p-4">
                                        <dt class="font-medium text-slate-700">{{ __('Sites') }}</dt>
                                        <dd class="mt-1 text-slate-900">
                                            <span class="font-semibold tabular-nums">{{ $organization->sites_count }}</span>
                                            @if ($organization->maxSites() >= PHP_INT_MAX)
                                                <span class="text-slate-600">{{ __('(unlimited)') }}</span>
                                            @else
                                                <span class="text-slate-600">{{ __('of :count', ['count' => $organization->maxSitesDisplay()]) }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                    <p class="text-xs text-slate-500">
                                        <strong class="text-slate-700">{{ __('Roles:') }}</strong>
                                        {{ __('Deployers cannot add servers or sites or use credentials. Only owners and admins can delete sites.') }}
                                        <a href="{{ route('docs.org-roles-and-limits') }}" class="ml-1 text-indigo-600 underline hover:text-indigo-800">{{ __('Full details') }}</a>
                                    </p>
                                </div>
                            </section>

                            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                                <div class="border-b border-slate-200 px-6 py-4">
                                    <h3 class="font-medium text-slate-900">{{ __('Quick links') }}</h3>
                                    <p class="mt-1 text-sm text-slate-500">{{ __('Jump to the focused pages for billing, notifications, credentials, and templates.') }}</p>
                                </div>
                                <div class="space-y-2 px-6 py-4 text-sm">
                                    @if ($organization->hasAdminAccess(auth()->user()))
                                        <a href="{{ route('billing.show', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-slate-700 hover:bg-slate-50">
                                            <span>{{ __('Billing & plan') }}</span>
                                            <span aria-hidden="true">→</span>
                                        </a>
                                    @endif
                                    @can('viewNotificationChannels', $organization)
                                        <a href="{{ route('organizations.notification-channels', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-slate-700 hover:bg-slate-50">
                                            <span>{{ __('Notification channels') }}</span>
                                            <span aria-hidden="true">→</span>
                                        </a>
                                    @endcan
                                    @can('viewAny', \App\Models\ProviderCredential::class)
                                        <a href="{{ route('organizations.credentials', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-slate-700 hover:bg-slate-50">
                                            <span>{{ __('Server providers') }}</span>
                                            <span aria-hidden="true">→</span>
                                        </a>
                                    @endcan
                                    @can('view', $organization)
                                        <a href="{{ route('organizations.webserver-templates', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-slate-700 hover:bg-slate-50">
                                            <span>{{ __('Webserver templates') }}</span>
                                            <span aria-hidden="true">→</span>
                                        </a>
                                    @endcan
                                </div>
                            </section>
                        </section>
                    </section>

                @if ($organization->hasAdminAccess(auth()->user()))
                    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-6 py-4">
                            <h3 class="font-medium text-slate-900">{{ __('Admin controls') }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Keep automation and notification wiring available here, but visually secondary to the organization summary above.') }}</p>
                        </div>
                        <div class="space-y-6 px-6 py-4">
                            <section class="rounded-xl border border-slate-200 bg-slate-50/50 p-4" id="notification-settings">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h4 class="text-sm font-medium text-slate-900">{{ __('Notification destinations & preferences') }}</h4>
                                        <p class="mt-1 text-sm text-slate-600">{{ __('Control deploy email behavior here, then manage saved channels and universal routing rules on the dedicated notifications page.') }}</p>
                                    </div>
                                    <a href="{{ route('organizations.notification-channels', $organization) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">{{ __('Manage saved destinations') }}</a>
                                </div>
                                <label class="mt-4 flex cursor-pointer items-center gap-3">
                                    <input type="checkbox" wire:model.live="deploy_email_notifications_enabled" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                    <span class="text-sm text-slate-700">{{ __('Send deploy emails for sites in this organization') }}</span>
                                </label>
                                @if ($organization->notificationWebhookDestinations->isNotEmpty())
                                    <div class="mt-4 flex flex-wrap gap-2">
                                        @foreach ($organization->notificationWebhookDestinations as $hook)
                                            <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs text-slate-700 border border-slate-200">
                                                {{ $hook->name }} · {{ $hook->driver }} · {{ $hook->enabled ? __('on') : __('off') }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </section>

                            <section class="rounded-xl border border-slate-200 bg-white p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h4 class="text-sm font-medium text-slate-900">{{ __('API tokens') }}</h4>
                                        <p class="mt-1 text-sm text-slate-600">{{ __('Create scoped organization tokens for CI/CD and automation.') }}</p>
                                    </div>
                                </div>
                                <form wire:submit="createApiToken" class="mt-4 flex flex-wrap items-end gap-2">
                                    <div>
                                        <label for="token_name" class="sr-only">Token name</label>
                                        <input type="text" id="token_name" wire:model="token_name" placeholder="e.g. GitHub Actions" required maxlength="255" class="rounded-md border-slate-300 shadow-sm text-sm">
                                    </div>
                                    <div>
                                        <label for="token_expires_at" class="sr-only">Expires (optional)</label>
                                        <input type="date" id="token_expires_at" wire:model="token_expires_at" min="{{ date('Y-m-d', strtotime('+1 day')) }}" class="rounded-md border-slate-300 shadow-sm text-sm">
                                    </div>
                                    <div>
                                        <label for="token_scope" class="sr-only">Scope</label>
                                        <select id="token_scope" wire:model="token_scope" class="rounded-md border-slate-300 shadow-sm text-sm">
                                            <option value="full">Full access</option>
                                            <option value="read">Read only</option>
                                            <option value="deploy">Read + deploy</option>
                                            <option value="ops">Deploy + ops</option>
                                        </select>
                                    </div>
                                    <x-primary-button type="submit" class="!text-sm">{{ __('Create token') }}</x-primary-button>
                                </form>
                                <div class="mt-3">
                                    <label for="token_allowed_ips_text" class="mb-1 block text-xs font-medium text-slate-600">{{ __('Optional IP allow list') }}</label>
                                    <textarea id="token_allowed_ips_text" wire:model="token_allowed_ips_text" rows="3" class="w-full max-w-xl rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="Leave empty to allow any IP"></textarea>
                                    @error('token_allowed_ips_text')
                                        <span class="text-red-600 text-xs">{{ $message }}</span>
                                    @enderror
                                </div>
                                @if ($organization->apiTokens->isEmpty())
                                    <p class="mt-4 text-sm text-slate-500">{{ __('No API tokens yet.') }}</p>
                                @else
                                    <ul class="mt-4 divide-y divide-slate-200 border-t border-slate-100">
                                        @foreach ($organization->apiTokens as $apiToken)
                                            <li class="flex items-center justify-between gap-4 py-3">
                                                <div class="min-w-0">
                                                    <span class="font-medium text-slate-900">{{ $apiToken->name }}</span>
                                                    <span class="ml-2 font-mono text-sm text-slate-500">{{ $apiToken->token_prefix }}…</span>
                                                    @if ($apiToken->last_used_at)
                                                        <span class="ml-2 text-xs text-slate-400">{{ __('Last used :time', ['time' => $apiToken->last_used_at->diffForHumans()]) }}</span>
                                                    @endif
                                                    @if ($apiToken->expires_at)
                                                        <span class="ml-2 text-xs text-slate-400">{{ __('Expires :date', ['date' => $apiToken->expires_at->format('M j, Y')]) }}</span>
                                                    @endif
                                                </div>
                                                <button type="button" wire:click="openConfirmActionModal('revokeApiToken', ['{{ $apiToken->id }}'], @js(__('Revoke API token')), @js(__('Revoke this token? It will stop working immediately.')), @js(__('Revoke')), true)" class="shrink-0 text-sm text-red-600 hover:underline">{{ __('Revoke') }}</button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </section>

                            <details class="rounded-xl border border-slate-200 bg-white p-4">
                                <summary class="cursor-pointer list-none text-sm font-medium text-slate-900">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <span>{{ __('Webhook destinations') }}</span>
                                            <p class="mt-1 text-sm font-normal text-slate-600">{{ __('Advanced outbound hooks for deploy and insight events.') }}</p>
                                        </div>
                                        <span class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ __('Expand') }}</span>
                                    </div>
                                </summary>
                                <div class="mt-4 space-y-4 border-t border-slate-100 pt-4">
                                    <form wire:submit="saveWebhookDestination" class="flex max-w-2xl flex-col gap-3">
                                        <div class="flex flex-wrap gap-2">
                                            <input type="text" wire:model="int_hook_name" placeholder="Destination name" required class="min-w-[140px] flex-1 rounded-md border-slate-300 text-sm shadow-sm">
                                            <select wire:model="int_hook_driver" class="rounded-md border-slate-300 text-sm shadow-sm">
                                                <option value="slack">Slack</option>
                                                <option value="discord">Discord</option>
                                                <option value="teams">Microsoft Teams</option>
                                            </select>
                                        </div>
                                        <input type="url" wire:model="int_hook_url" placeholder="Incoming webhook URL" required class="w-full rounded-md border-slate-300 font-mono text-xs shadow-sm">
                                        <div>
                                            <label for="int_hook_site_id" class="mb-1 block text-xs font-medium text-slate-600">{{ __('Limit this destination to one site (optional)') }}</label>
                                            <select id="int_hook_site_id" wire:model="int_hook_site_id" class="w-full max-w-md rounded-md border-slate-300 text-sm shadow-sm">
                                                <option value="">{{ __('All sites in this org') }}</option>
                                                @foreach ($organization->sites as $s)
                                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-slate-700">
                                            <span class="w-full text-xs font-medium text-slate-500">{{ __('Deploy events') }}</span>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_success" class="rounded border-slate-300"> {{ __('Success') }}</label>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_failed" class="rounded border-slate-300"> {{ __('Failed') }}</label>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_skipped" class="rounded border-slate-300"> {{ __('Skipped') }}</label>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_deploy_started" class="rounded border-slate-300"> {{ __('Deployment started') }}</label>
                                            <span class="w-full text-xs font-medium text-slate-500">{{ __('Uptime') }}</span>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_uptime_down" class="rounded border-slate-300"> {{ __('Monitor down') }}</label>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_uptime_recovered" class="rounded border-slate-300"> {{ __('Monitor recovered') }}</label>
                                            <span class="w-full text-xs font-medium text-slate-500">{{ __('Insight events (org-wide only)') }}</span>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_insight_opened" class="rounded border-slate-300"> {{ __('Opened') }}</label>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_insight_resolved" class="rounded border-slate-300"> {{ __('Resolved') }}</label>
                                        </div>
                                        <x-primary-button type="submit" class="!text-sm w-fit">{{ __('Add webhook destination') }}</x-primary-button>
                                    </form>
                                    @if ($organization->notificationWebhookDestinations->isEmpty())
                                        <p class="text-sm text-slate-500">{{ __('No webhook destinations yet.') }}</p>
                                    @else
                                        <ul class="divide-y divide-slate-100 rounded-md border border-slate-100">
                                            @foreach ($organization->notificationWebhookDestinations as $hook)
                                                <li class="flex flex-wrap justify-between gap-2 px-4 py-3 text-sm">
                                                    <div>
                                                        <span class="font-medium">{{ $hook->name }}</span>
                                                        <span class="ml-2 text-slate-500">{{ $hook->driver }}</span>
                                                        @if ($hook->site_id)
                                                            <span class="ml-2 text-xs text-slate-400">site #{{ $hook->site_id }}</span>
                                                        @endif
                                                        <span class="ml-2 text-xs {{ $hook->enabled ? 'text-green-600' : 'text-slate-400' }}">{{ $hook->enabled ? 'on' : 'off' }}</span>
                                                    </div>
                                                    <div class="flex gap-2">
                                                        <button type="button" wire:click="toggleWebhookDestination('{{ $hook->id }}')" class="text-xs text-slate-600 hover:underline">{{ __('Toggle') }}</button>
                                                        <button type="button" wire:click="openConfirmActionModal('deleteWebhookDestination', ['{{ $hook->id }}'], @js(__('Remove webhook destination')), @js(__('Remove this webhook destination?')), @js(__('Remove')), true)" class="text-xs text-red-600 hover:underline">{{ __('Remove') }}</button>
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </details>
                        </div>
                    </section>
                @endif

                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-6 py-4">
                        <div>
                            <h3 class="font-medium text-slate-900">{{ __('Teams') }}</h3>
                            <p class="text-sm text-slate-500">{{ __('Group servers and control access by team.') }}</p>
                        </div>
                        @if ($organization->hasAdminAccess(auth()->user()))
                            <form wire:submit="createTeam" class="flex gap-2">
                                <input type="text" wire:model="team_name" placeholder="Team name" required class="rounded-md border-slate-300 shadow-sm text-sm">
                                <x-primary-button type="submit" class="!text-sm">{{ __('Create team') }}</x-primary-button>
                            </form>
                        @endif
                    </div>
                    @if ($organization->teams->isEmpty())
                        <div class="px-6 py-8 text-center text-slate-500 text-sm">No teams yet. Create one above.</div>
                    @else
                        <ul class="divide-y divide-slate-200">
                            @foreach ($organization->teams as $team)
                                <li class="px-6 py-4">
                                    <div class="flex justify-between items-start gap-4">
                                        <div class="min-w-0">
                                            <input type="text" wire:model="teamNames.{{ $team->id }}" wire:blur="updateTeam({{ $team->id }})" class="font-medium text-slate-900 border-0 border-b border-transparent hover:border-slate-300 focus:border-slate-500 focus:ring-0 text-sm p-0 bg-transparent">
                                            @error('teamNames.'.$team->id)
                                                <span class="text-red-600 text-xs">{{ $message }}</span>
                                            @enderror
                                            <p class="text-slate-500 text-sm mt-1">{{ $team->users->count() }} members</p>
                                            <p class="mt-2">
                                                <a href="{{ route('teams.notification-channels', [$organization, $team]) }}" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">{{ __('Team notification channels') }} →</a>
                                            </p>
                                        </div>
                                        @if ($organization->hasAdminAccess(auth()->user()))
                                            <div class="flex gap-2 shrink-0">
                                                <button type="button" wire:click="openConfirmActionModal('deleteTeam', ['{{ $team->id }}'], @js(__('Delete team')), @js(__('Remove this team?')), @js(__('Delete')), true)" class="text-red-600 hover:underline text-sm">Delete</button>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2 items-center">
                                        @foreach ($team->users as $member)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                                                {{ $member->name }}
                                                <button type="button" wire:click="removeTeamMember({{ $team->id }}, {{ $member->id }})" class="text-slate-400 hover:text-red-600">&times;</button>
                                            </span>
                                        @endforeach
                                        @if ($organization->hasAdminAccess(auth()->user()) && $organization->users->isNotEmpty())
                                            <div class="inline flex gap-1">
                                                <select wire:model="addMemberSelected.{{ $team->id }}" class="rounded border-slate-300 text-xs py-0.5">
                                                    <option value="">Add member…</option>
                                                    @foreach ($organization->users->diff($team->users) as $u)
                                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="button" wire:click="addTeamMember({{ $team->id }})" class="text-slate-600 hover:underline text-xs">Add</button>
                                            </div>
                                            @error('team_'.$team->id)
                                                <span class="text-red-600 text-xs">{{ $message }}</span>
                                            @enderror
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @if ($organization->hasAdminAccess(auth()->user()))
                    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-6 py-4">
                            <h3 class="font-medium text-slate-900">{{ __('Recent activity') }}</h3>
                            <p class="text-sm text-slate-500">{{ __('The latest audit events for this organization.') }}</p>
                        </div>
                        @if ($this->auditLogs->isEmpty())
                            <div class="px-6 py-8 text-center text-slate-500 text-sm">No activity yet.</div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200">
                                    <thead class="bg-slate-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-2 text-left text-xs font-medium text-slate-500 uppercase">Date</th>
                                            <th scope="col" class="px-6 py-2 text-left text-xs font-medium text-slate-500 uppercase">User</th>
                                            <th scope="col" class="px-6 py-2 text-left text-xs font-medium text-slate-500 uppercase">Action</th>
                                            <th scope="col" class="px-6 py-2 text-left text-xs font-medium text-slate-500 uppercase">Subject</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200">
                                        @foreach ($this->auditLogs as $log)
                                            <tr>
                                                <td class="px-6 py-3 text-sm text-slate-600 whitespace-nowrap">{{ $log->created_at->format('M j, Y g:i A') }}</td>
                                                <td class="px-6 py-3 text-sm text-slate-900">{{ $log->user?->name ?? '—' }}</td>
                                                <td class="px-6 py-3 text-sm text-slate-700">{{ $log->action }}</td>
                                                <td class="px-6 py-3 text-sm text-slate-600">{{ $log->subject_summary ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </section>
                @endif
            </div>
        </x-organization-shell>

        <x-slot name="modals">
            @include('livewire.partials.confirm-action-modal')
        </x-slot>
    </div>
</div>
