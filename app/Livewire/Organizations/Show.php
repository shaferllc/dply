<?php

namespace App\Livewire\Organizations;

use App\Models\ApiToken;
use App\Models\IntegrationOutboundWebhook;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Team;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Organization $organization;

    public string $invite_email = '';

    public string $invite_role = 'member';

    public string $token_name = '';

    public ?string $token_expires_at = null;

    /** full | read | deploy | ops — maps to API abilities */
    public string $token_scope = 'full';

    public string $token_allowed_ips_text = '';

    public string $int_hook_name = '';

    public string $int_hook_driver = IntegrationOutboundWebhook::DRIVER_SLACK;

    public string $int_hook_url = '';

    public ?int $int_hook_site_id = null;

    public bool $int_evt_success = true;

    public bool $int_evt_failed = true;

    public bool $int_evt_skipped = true;

    public string $team_name = '';

    public ?string $new_token_plaintext = null;

    public ?string $new_token_name = null;

    /** @var array<int, string> team id => name for inline edit */
    public array $teamNames = [];

    /** @var array<int, int> team id => user id for "add member" dropdown */
    public array $addMemberSelected = [];

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->refreshOrganization();
        $this->syncTeamNames();
    }

    protected function refreshOrganization(): void
    {
        $this->organization = $this->organization->fresh()->load([
            'users',
            'teams' => fn ($q) => $q->withCount('users')->with('users'),
            'invitations' => fn ($q) => $q->where('expires_at', '>', now()),
            'apiTokens',
            'integrationOutboundWebhooks',
            'sites' => fn ($q) => $q->orderBy('name'),
        ]);
        $this->syncTeamNames();
    }

    protected function syncTeamNames(): void
    {
        $this->teamNames = $this->organization->teams->keyBy('id')->map(fn ($t) => $t->name)->all();
    }

    public function getAuditLogsProperty(): Collection
    {
        if (! $this->organization->hasAdminAccess(auth()->user())) {
            return collect();
        }

        return $this->organization->auditLogs()
            ->with('user')
            ->latest()
            ->limit(50)
            ->get();
    }

    public function inviteMember(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'invite_email' => 'required|email',
            'invite_role' => 'nullable|string|in:admin,member,deployer',
        ]);

        $email = strtolower($this->invite_email);
        if ($this->organization->users()->where('users.email', $email)->exists()) {
            throw ValidationException::withMessages(['invite_email' => 'That user is already a member.']);
        }
        if ($this->organization->invitations()->where('email', $email)->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['invite_email' => 'An invitation has already been sent to that address.']);
        }

        $maxMembers = $this->organization->effectiveMemberSeatCap();
        if ($maxMembers !== null) {
            $current = $this->organization->users()->count();
            $pending = $this->organization->invitations()->where('expires_at', '>', now())->count();
            if ($current + $pending >= $maxMembers) {
                throw ValidationException::withMessages([
                    'invite_email' => 'This organization has reached its member limit ('.$maxMembers.').',
                ]);
            }
        }

        $invitation = OrganizationInvitation::createFor(
            $this->organization,
            $email,
            $this->invite_role ?: 'member',
            auth()->user()
        );

        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification($invitation));
        audit_log($this->organization, auth()->user(), 'invitation.sent', $invitation);

        $this->reset(['invite_email', 'invite_role']);
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Invitation sent to '.$email);
    }

    public function cancelInvitation(int $invitationId): void
    {
        $this->authorize('update', $this->organization);

        $invitation = $this->organization->invitations()->findOrFail($invitationId);
        $invitation->delete();
        audit_log($this->organization, auth()->user(), 'invitation.cancelled', $invitation);

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Invitation cancelled.');
    }

    public function createApiToken(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'token_name' => 'required|string|max:255',
            'token_expires_at' => 'nullable|date|after:today',
            'token_allowed_ips_text' => 'nullable|string|max:4000',
        ]);

        $expiresAt = $this->token_expires_at ? Carbon::parse($this->token_expires_at) : null;
        if ($expiresAt === null && $this->token_scope === 'deploy') {
            $expiresAt = now()->addDays((int) config('dply.api_token_deploy_default_ttl_days', 14));
        }
        $abilities = match ($this->token_scope) {
            'read' => ['servers.read', 'sites.read'],
            'deploy' => ['servers.read', 'sites.read', 'servers.deploy', 'sites.deploy'],
            'ops' => ['servers.read', 'sites.read', 'servers.deploy', 'sites.deploy', 'commands.run'],
            default => ['*'],
        };
        $allowedIps = $this->parseTokenAllowedIps($this->token_allowed_ips_text);
        ['token' => $token, 'plaintext' => $plaintext] = ApiToken::createToken(
            auth()->user(),
            $this->organization,
            $this->token_name,
            $expiresAt,
            $abilities,
            $allowedIps
        );

        $this->new_token_plaintext = $plaintext;
        $this->new_token_name = $token->name;
        $this->reset(['token_name', 'token_expires_at', 'token_allowed_ips_text']);
        $this->refreshOrganization();
    }

    public function clearNewToken(): void
    {
        $this->new_token_plaintext = null;
        $this->new_token_name = null;
    }

    public function revokeApiToken(int $apiTokenId): void
    {
        $this->authorize('update', $this->organization);

        $apiToken = ApiToken::where('organization_id', $this->organization->id)->findOrFail($apiTokenId);
        $apiToken->delete();

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'API token revoked.');
    }

    public function createTeam(): void
    {
        $this->validate([
            'team_name' => 'required|string|max:255',
        ]);

        $this->authorize('create', [Team::class, $this->organization]);

        $slug = Str::slug(Str::limit($this->team_name, 50));
        $base = $slug;
        $i = 0;
        while (Team::where('organization_id', $this->organization->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        $this->organization->teams()->create([
            'name' => $this->team_name,
            'slug' => $slug,
        ]);
        audit_log($this->organization, auth()->user(), 'team.created', $this->organization->teams()->latest()->first());

        $this->reset('team_name');
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Team created.');
    }

    public function updateTeam(int $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);

        $name = $this->teamNames[$teamId] ?? $team->name;
        $key = 'teamNames.'.$teamId;
        $this->validate([
            $key => 'required|string|max:255',
        ], [], [$key => 'name']);
        $oldName = $team->name;
        $team->update(['name' => $name]);
        audit_log($this->organization, auth()->user(), 'team.updated', $team, ['name' => $oldName], ['name' => $name]);

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Team updated.');
    }

    public function deleteTeam(int $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('delete', $team);
        $org = $team->organization;
        audit_log($org, auth()->user(), 'team.deleted', $team, ['name' => $team->name], null);
        $team->delete();

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Team removed.');
    }

    public function addTeamMember(int $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);

        $userId = (int) ($this->addMemberSelected[$teamId] ?? 0);
        if (! $userId) {
            $this->addError('team_'.$teamId, 'Select a user to add.');

            return;
        }
        if (! $team->organization->hasMember(User::find($userId))) {
            $this->addError('team_'.$teamId, 'User must be an organization member first.');

            return;
        }
        if ($team->users()->where('user_id', $userId)->exists()) {
            $this->addError('team_'.$teamId, 'User is already on this team.');

            return;
        }
        $team->users()->attach($userId, ['role' => 'member']);
        $this->addMemberSelected[$teamId] = '';

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Member added to team.');
    }

    public function removeTeamMember(int $teamId, int $userId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);
        $team->users()->detach($userId);

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Member removed from team.');
    }

    public function saveOutboundIntegration(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'int_hook_name' => 'required|string|max:120',
            'int_hook_driver' => 'required|string|in:slack,discord,teams',
            'int_hook_url' => 'required|string|url|max:2000',
            'int_hook_site_id' => 'nullable',
        ]);

        $siteId = $this->int_hook_site_id ? (int) $this->int_hook_site_id : null;
        if ($siteId && ! $this->organization->sites()->whereKey($siteId)->exists()) {
            throw ValidationException::withMessages(['int_hook_site_id' => 'Invalid site for this organization.']);
        }

        $events = [];
        if ($this->int_evt_success) {
            $events[] = 'deploy_success';
        }
        if ($this->int_evt_failed) {
            $events[] = 'deploy_failed';
        }
        if ($this->int_evt_skipped) {
            $events[] = 'deploy_skipped';
        }

        IntegrationOutboundWebhook::query()->create([
            'organization_id' => $this->organization->id,
            'site_id' => $siteId,
            'name' => $this->int_hook_name,
            'driver' => $this->int_hook_driver,
            'webhook_url' => $this->int_hook_url,
            'events' => $events !== [] ? $events : null,
            'enabled' => true,
        ]);

        $this->reset(['int_hook_name', 'int_hook_url', 'int_hook_site_id']);
        $this->int_hook_driver = IntegrationOutboundWebhook::DRIVER_SLACK;
        $this->int_evt_success = true;
        $this->int_evt_failed = true;
        $this->int_evt_skipped = true;
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Integration webhook saved.');
    }

    public function deleteOutboundIntegration(int $id): void
    {
        $this->authorize('update', $this->organization);
        $hook = $this->organization->integrationOutboundWebhooks()->whereKey($id)->firstOrFail();
        $hook->delete();
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Integration removed.');
    }

    public function toggleOutboundIntegration(int $id): void
    {
        $this->authorize('update', $this->organization);
        $hook = $this->organization->integrationOutboundWebhooks()->whereKey($id)->firstOrFail();
        $hook->update(['enabled' => ! $hook->enabled]);
        $this->refreshOrganization();
    }

    /**
     * @return array<int, string>|null
     */
    protected function parseTokenAllowedIps(string $raw): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! $this->validIpOrCidrForToken($line)) {
                throw ValidationException::withMessages([
                    'token_allowed_ips_text' => 'Invalid IP or CIDR: '.$line,
                ]);
            }
            $clean[] = $line;
        }

        return $clean !== [] ? $clean : null;
    }

    protected function validIpOrCidrForToken(string $value): bool
    {
        if (str_contains($value, '/')) {
            return (bool) preg_match('#^(\d{1,3}\.){3}\d{1,3}/(3[0-2]|[12]?\d)$#', $value);
        }

        return (bool) filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);
    }

    public function render(): View
    {
        return view('livewire.organizations.show');
    }
}
