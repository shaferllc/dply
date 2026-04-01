<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="overview">
            <div>
                <header class="mb-8">
                    <h1 class="text-2xl font-semibold text-slate-800">{{ __('Overview') }}</h1>
                    <p class="mt-1 text-sm text-slate-600">{{ __('Members, teams, API tokens, and activity for :name.', ['name' => $organization->name]) }}</p>
                </header>

            @if ($new_token_plaintext)
                <div class="mb-4 p-4 rounded-md bg-amber-50 border border-amber-200">
                    <p class="font-medium text-amber-900 mb-1">API token created: {{ $new_token_name }}</p>
                    <p class="text-sm text-amber-800 mb-2">Copy this token now. It won't be shown again.</p>
                    <code class="block p-3 bg-white rounded border border-amber-200 text-sm break-all select-all">{{ $new_token_plaintext }}</code>
                    <button type="button" wire:click="clearNewToken" class="mt-2 text-sm text-amber-800 underline">Dismiss</button>
                </div>
            @endif
            <div class="space-y-8">
                {{-- Plan & usage (all members) --}}
                <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-slate-200">
                        <h3 class="font-medium text-slate-900">Plan &amp; usage</h3>
                        <p class="text-sm text-slate-500 mt-1">{{ __('Limits apply to this entire organization. Admins can change plans under Billing & plan in the sidebar.') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                            <div class="rounded-md border border-slate-100 bg-slate-50/80 p-4">
                                <dt class="text-slate-500 font-medium">Plan</dt>
                                <dd class="mt-1 text-slate-900 font-semibold">{{ $organization->planTierLabel() }}</dd>
                            </div>
                            <div class="rounded-md border border-slate-100 bg-slate-50/80 p-4">
                                <dt class="text-slate-500 font-medium">Servers</dt>
                                <dd class="mt-1 text-slate-900 font-semibold">
                                    <span class="tabular-nums">{{ $organization->servers_count }}</span>
                                    @if ($organization->maxServers() >= PHP_INT_MAX)
                                        <span class="text-slate-600 font-normal"> (unlimited)</span>
                                    @else
                                        <span class="text-slate-600 font-normal"> of {{ $organization->maxServersDisplay() }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="rounded-md border border-slate-100 bg-slate-50/80 p-4">
                                <dt class="text-slate-500 font-medium">Sites</dt>
                                <dd class="mt-1 text-slate-900 font-semibold">
                                    <span class="tabular-nums">{{ $organization->sites_count }}</span>
                                    @if ($organization->maxSites() >= PHP_INT_MAX)
                                        <span class="text-slate-600 font-normal"> (unlimited)</span>
                                    @else
                                        <span class="text-slate-600 font-normal"> of {{ $organization->maxSitesDisplay() }}</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                        <p class="mt-4 text-xs text-slate-500">
                            <strong class="text-slate-700">Roles:</strong> Deployers cannot add servers or sites or use credentials. Only owners and admins can delete sites.
                            <a href="{{ route('docs.org-roles-and-limits') }}" class="text-indigo-600 hover:text-indigo-800 underline ml-1">Full details</a>
                        </p>
                    </div>
                </section>

                @if ($organization->hasAdminAccess(auth()->user()))
                    <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg" id="notification-settings">
                        <div class="px-6 py-4 border-b border-slate-200">
                            <h3 class="font-medium text-slate-900">Notification destinations &amp; preferences</h3>
                            <p class="text-sm text-slate-500 mt-1">
                                Use this section to control where organization notifications go. The universal notification system now supports the in-app inbox, email, saved notification channels, and webhook destinations from the same event pipeline.
                            </p>
                        </div>
                        <div class="px-6 py-4 space-y-5">
                            <div class="rounded-md border border-slate-200 bg-slate-50/80 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h4 class="text-sm font-medium text-slate-900">Deploy email destination</h4>
                                        <p class="mt-1 text-sm text-slate-600">
                                            When enabled, site owners and org owners/admins receive email when a deploy finishes, or digest mail if <code class="text-xs bg-slate-100 px-1 rounded">DPLY_DEPLOY_DIGEST_HOURS</code> is set.
                                        </p>
                                    </div>
                                    <a href="{{ route('organizations.notification-channels', $organization) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                                        Manage saved destinations
                                    </a>
                                </div>
                                <label class="mt-4 flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" wire:model.live="deploy_email_notifications_enabled" class="rounded border-slate-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                    <span class="text-sm text-slate-700">Send deploy emails for sites in this organization</span>
                                </label>
                            </div>

                            <div class="rounded-md border border-slate-200 bg-white p-4">
                                <h4 class="text-sm font-medium text-slate-900">How this fits together</h4>
                                <dl class="mt-3 grid gap-3 md:grid-cols-3 text-sm">
                                    <div>
                                        <dt class="font-medium text-slate-800">Inbox</dt>
                                        <dd class="mt-1 text-slate-600">Every published event can appear in-app so operators have one shared activity stream.</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium text-slate-800">Destinations</dt>
                                        <dd class="mt-1 text-slate-600">Saved channels and webhook endpoints receive copies when their rules match an event.</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium text-slate-800">Rules</dt>
                                        <dd class="mt-1 text-slate-600">Projects, servers, sites, and org-level settings decide which destinations get which events.</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </section>
                @endif

                {{-- Members + Invite --}}
                <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center">
                        <div>
                            <h3 class="font-medium text-slate-900">Members</h3>
                            <p class="text-sm text-slate-500">Members can add servers and sites within plan limits. Deployers can deploy but cannot add servers/sites, open credentials, or billing. Only owners and admins can delete sites.</p>
                        </div>
                        @if ($organization->hasAdminAccess(auth()->user()))
                            <form wire:submit="inviteMember" class="flex gap-2 items-end flex-wrap">
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
                                <x-primary-button type="submit" class="!text-sm">Invite</x-primary-button>
                            </form>
                        @endif
                    </div>
                    @if ($organization->invitations->isNotEmpty())
                        <div class="px-6 py-3 border-b border-slate-100 bg-slate-50">
                            <p class="text-xs font-medium text-slate-500 uppercase mb-2">Pending invitations</p>
                            <ul class="space-y-1">
                                @foreach ($organization->invitations as $inv)
                                    <li class="flex items-center justify-between text-sm">
                                        <span>{{ $inv->email }} ({{ $inv->role }})</span>
                                        @if ($organization->hasAdminAccess(auth()->user()))
                                            <button type="button" wire:click="openConfirmActionModal('cancelInvitation', ['{{ $inv->id }}'], @js(__('Cancel invitation')), @js(__('Cancel this invitation?')), @js(__('Cancel invitation')), true)" class="text-red-600 hover:underline">Cancel</button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <ul class="divide-y divide-slate-200">
                        @foreach ($organization->users as $user)
                            <li class="flex items-center justify-between px-6 py-3">
                                <div>
                                    <span class="font-medium text-slate-900">{{ $user->name }}</span>
                                    <span class="text-slate-500 text-sm ml-2">{{ $user->email }}</span>
                                </div>
                                <span class="text-xs font-medium text-slate-600 uppercase">{{ $user->pivot->role }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{-- API Tokens (org admins) --}}
                @if ($organization->hasAdminAccess(auth()->user()))
                    <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center flex-wrap gap-2">
                            <div>
                                <h3 class="font-medium text-slate-900">API tokens</h3>
                                <p class="text-sm text-slate-500">For CI/CD: list servers and trigger deploys. Use in <code class="text-xs bg-slate-100 px-1">Authorization: Bearer &lt;token&gt;</code> or <code class="text-xs bg-slate-100 px-1">X-API-Key</code>. See <code class="text-xs bg-slate-100 px-1">docs/API.md</code> for full API docs.</p>
                            </div>
                            <form wire:submit="createApiToken" class="flex gap-2 items-end flex-wrap">
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
                                    <select id="token_scope" wire:model="token_scope" class="rounded-md border-slate-300 shadow-sm text-sm" title="API abilities">
                                        <option value="full">Full access</option>
                                        <option value="read">Read only (list servers/sites)</option>
                                        <option value="deploy">Read + deploy</option>
                                        <option value="ops">Deploy + run server commands</option>
                                    </select>
                                </div>
                                <x-primary-button type="submit" class="!text-sm">Create token</x-primary-button>
                            </form>
                            <div class="px-6 pb-4">
                                <label for="token_allowed_ips_text" class="block text-xs font-medium text-slate-600 mb-1">Optional API token IP allow list (one IPv4/IPv6 or IPv4 CIDR per line)</label>
                                <textarea id="token_allowed_ips_text" wire:model="token_allowed_ips_text" rows="3" class="w-full max-w-xl rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="Leave empty to allow any IP"></textarea>
                                @error('token_allowed_ips_text')
                                    <span class="text-red-600 text-xs">{{ $message }}</span>
                                @enderror
                                <p class="text-xs text-slate-500 mt-1">Deploy scope defaults to a short expiry ({{ config('dply.api_token_deploy_default_ttl_days', 14) }} days) if you leave “Expires” blank.</p>
                            </div>
                        </div>
                        @if ($organization->apiTokens->isEmpty())
                            <div class="px-6 py-6 text-slate-500 text-sm">No API tokens yet. Create one above to use the API from CI/CD.</div>
                        @else
                            <ul class="divide-y divide-slate-200">
                                @foreach ($organization->apiTokens as $apiToken)
                                    <li class="flex items-center justify-between px-6 py-3">
                                        <div>
                                            <span class="font-medium text-slate-900">{{ $apiToken->name }}</span>
                                            <span class="text-slate-500 text-sm ml-2 font-mono">{{ $apiToken->token_prefix }}…</span>
                                            @if ($apiToken->last_used_at)
                                                <span class="text-slate-400 text-xs ml-2">Last used {{ $apiToken->last_used_at->diffForHumans() }}</span>
                                            @endif
                                            @if ($apiToken->expires_at)
                                                <span class="text-slate-400 text-xs ml-2">Expires {{ $apiToken->expires_at->format('M j, Y') }}</span>
                                            @endif
                                            @if ($apiToken->abilities)
                                                <p class="text-xs text-slate-500 mt-1 font-mono">{{ implode(', ', $apiToken->abilities) }}</p>
                                            @endif
                                            @if ($apiToken->allowed_ips)
                                                <p class="text-xs text-slate-500 mt-1">IPs: {{ implode(', ', $apiToken->allowed_ips) }}</p>
                                            @endif
                                        </div>
                                        <button type="button" wire:click="openConfirmActionModal('revokeApiToken', ['{{ $apiToken->id }}'], @js(__('Revoke API token')), @js(__('Revoke this token? It will stop working immediately.')), @js(__('Revoke')), true)" class="text-red-600 hover:underline text-sm">Revoke</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>

                    <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="px-6 py-4 border-b border-slate-200">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-medium text-slate-900">Webhook destinations</h3>
                                    <p class="text-sm text-slate-500 mt-1">{{ __('These destinations are part of the shared notification system. They receive deploy and insight events from the same universal pipeline that powers the inbox and saved channels. Org-wide hooks fire for every site; site-specific hooks only when that site deploys.') }}</p>
                                </div>
                                <a href="{{ route('organizations.notification-channels', $organization) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                                    Open notification channels
                                </a>
                            </div>
                        </div>
                        <div class="px-6 py-4 space-y-4">
                            <form wire:submit="saveWebhookDestination" class="flex flex-col gap-3 max-w-2xl">
                                <div class="flex flex-wrap gap-2">
                                    <input type="text" wire:model="int_hook_name" placeholder="Destination name" required class="rounded-md border-slate-300 shadow-sm text-sm flex-1 min-w-[140px]">
                                    <select wire:model="int_hook_driver" class="rounded-md border-slate-300 shadow-sm text-sm">
                                        <option value="slack">Slack</option>
                                        <option value="discord">Discord</option>
                                        <option value="teams">Microsoft Teams</option>
                                    </select>
                                </div>
                                <input type="url" wire:model="int_hook_url" placeholder="Incoming webhook URL" required class="rounded-md border-slate-300 shadow-sm text-sm w-full font-mono text-xs">
                                <div>
                                    <label for="int_hook_site_id" class="block text-xs font-medium text-slate-600 mb-1">Limit this destination to one site (optional)</label>
                                    <select id="int_hook_site_id" wire:model="int_hook_site_id" class="rounded-md border-slate-300 shadow-sm text-sm w-full max-w-md">
                                        <option value="">All sites in this org</option>
                                        @foreach ($organization->sites as $s)
                                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-slate-700">
                                    <span class="text-xs font-medium text-slate-500 w-full">{{ __('Deploy events') }}</span>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_success" class="rounded border-slate-300"> {{ __('Success') }}</label>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_failed" class="rounded border-slate-300"> {{ __('Failed') }}</label>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_skipped" class="rounded border-slate-300"> {{ __('Skipped') }}</label>
                                    <span class="text-xs font-medium text-slate-500 w-full sm:ml-0">{{ __('Insight events (org-wide destinations only)') }}</span>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_insight_opened" class="rounded border-slate-300"> {{ __('Opened') }}</label>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_insight_resolved" class="rounded border-slate-300"> {{ __('Resolved') }}</label>
                                </div>
                                <x-primary-button type="submit" class="!text-sm w-fit">Add webhook destination</x-primary-button>
                            </form>
                            @if ($organization->notificationWebhookDestinations->isEmpty())
                                <p class="text-sm text-slate-500">No webhook destinations yet.</p>
                            @else
                                <ul class="divide-y divide-slate-100 border border-slate-100 rounded-md">
                                    @foreach ($organization->notificationWebhookDestinations as $hook)
                                        <li class="px-4 py-3 flex flex-wrap justify-between gap-2 text-sm">
                                            <div>
                                                <span class="font-medium">{{ $hook->name }}</span>
                                                <span class="text-slate-500 ml-2">{{ $hook->driver }}</span>
                                                @if ($hook->site_id)
                                                    <span class="text-slate-400 text-xs ml-2">site #{{ $hook->site_id }}</span>
                                                @endif
                                                <span class="text-xs ml-2 {{ $hook->enabled ? 'text-green-600' : 'text-slate-400' }}">{{ $hook->enabled ? 'on' : 'off' }}</span>
                                            </div>
                                            <div class="flex gap-2">
                                                <button type="button" wire:click="toggleWebhookDestination('{{ $hook->id }}')" class="text-slate-600 hover:underline text-xs">Toggle</button>
                                                <button type="button" wire:click="openConfirmActionModal('deleteWebhookDestination', ['{{ $hook->id }}'], @js(__('Remove webhook destination')), @js(__('Remove this webhook destination?')), @js(__('Remove')), true)" class="text-red-600 hover:underline text-xs">Remove</button>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </section>
                @endif

                {{-- Teams CRUD --}}
                <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center flex-wrap gap-2">
                        <div>
                            <h3 class="font-medium text-slate-900">Teams</h3>
                            <p class="text-sm text-slate-500">Group servers and control access by team.</p>
                        </div>
                        @if ($organization->hasAdminAccess(auth()->user()))
                            <form wire:submit="createTeam" class="flex gap-2">
                                <input type="text" wire:model="team_name" placeholder="Team name" required class="rounded-md border-slate-300 shadow-sm text-sm">
                                <x-primary-button type="submit" class="!text-sm">Create team</x-primary-button>
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
                    <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="px-6 py-4 border-b border-slate-200">
                            <h3 class="font-medium text-slate-900">Activity</h3>
                            <p class="text-sm text-slate-500">Recent audit log for this organization (last 50).</p>
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
            </div>
        </x-organization-shell>

        <x-slot name="modals">
            @include('livewire.partials.confirm-action-modal')
        </x-slot>
    </div>
</div>
