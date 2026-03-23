<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center flex-wrap gap-2">
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ $organization->name }}</h2>
                <div class="flex items-center gap-3">
                    @if ($organization->hasAdminAccess(auth()->user()))
                        <a href="{{ route('billing.show', $organization) }}" class="text-slate-600 hover:text-slate-900 text-sm">Billing</a>
                    @endif
                    <a href="{{ route('organizations.index') }}" class="text-slate-600 hover:text-slate-900 text-sm">← Organizations</a>
                </div>
            </div>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if ($new_token_plaintext)
                <div class="mb-4 p-4 rounded-md bg-amber-50 border border-amber-200">
                    <p class="font-medium text-amber-900 mb-1">API token created: {{ $new_token_name }}</p>
                    <p class="text-sm text-amber-800 mb-2">Copy this token now. It won't be shown again.</p>
                    <code class="block p-3 bg-white rounded border border-amber-200 text-sm break-all select-all">{{ $new_token_plaintext }}</code>
                    <button type="button" wire:click="clearNewToken" class="mt-2 text-sm text-amber-800 underline">Dismiss</button>
                </div>
            @endif
            <div class="space-y-8">
                {{-- Members + Invite --}}
                <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center">
                        <div>
                            <h3 class="font-medium text-slate-900">Members</h3>
                            <p class="text-sm text-slate-500">Members and deployers can use servers and sites. Deployers cannot manage provider credentials, billing, or delete servers.</p>
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
                                            <button type="button" wire:click="cancelInvitation({{ $inv->id }})" wire:confirm="Cancel this invitation?" class="text-red-600 hover:underline">Cancel</button>
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
                                        </div>
                                        <button type="button" wire:click="revokeApiToken({{ $apiToken->id }})" wire:confirm="Revoke this token? It will stop working immediately." class="text-red-600 hover:underline text-sm">Revoke</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
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
                                        </div>
                                        @if ($organization->hasAdminAccess(auth()->user()))
                                            <div class="flex gap-2 shrink-0">
                                                <button type="button" wire:click="deleteTeam({{ $team->id }})" wire:confirm="Remove this team?" class="text-red-600 hover:underline text-sm">Delete</button>
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
    </div>
</div>
