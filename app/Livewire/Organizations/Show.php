<?php

namespace App\Livewire\Organizations;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Team;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
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
        ]);
        $this->syncTeamNames();
    }

    protected function syncTeamNames(): void
    {
        $this->teamNames = $this->organization->teams->keyBy('id')->map(fn ($t) => $t->name)->all();
    }

    public function getAuditLogsProperty(): \Illuminate\Database\Eloquent\Collection
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
            'invite_role' => 'nullable|string|in:admin,member',
        ]);

        $email = strtolower($this->invite_email);
        if ($this->organization->users()->where('users.email', $email)->exists()) {
            throw ValidationException::withMessages(['invite_email' => 'That user is already a member.']);
        }
        if ($this->organization->invitations()->where('email', $email)->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['invite_email' => 'An invitation has already been sent to that address.']);
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
        ]);

        $expiresAt = $this->token_expires_at ? \Carbon\Carbon::parse($this->token_expires_at) : null;
        $abilities = match ($this->token_scope) {
            'read' => ['servers.read', 'sites.read'],
            'deploy' => ['servers.read', 'sites.read', 'servers.deploy', 'sites.deploy'],
            'ops' => ['servers.read', 'sites.read', 'servers.deploy', 'sites.deploy', 'commands.run'],
            default => ['*'],
        };
        ['token' => $token, 'plaintext' => $plaintext] = ApiToken::createToken(
            auth()->user(),
            $this->organization,
            $this->token_name,
            $expiresAt,
            $abilities
        );

        $this->new_token_plaintext = $plaintext;
        $this->new_token_name = $token->name;
        $this->reset(['token_name', 'token_expires_at']);
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

        $slug = \Illuminate\Support\Str::slug(\Illuminate\Support\Str::limit($this->team_name, 50));
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
        if (! $team->organization->hasMember(\App\Models\User::find($userId))) {
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

    public function render(): View
    {
        return view('livewire.organizations.show');
    }
}
