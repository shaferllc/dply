<?php

namespace App\Livewire\Organizations;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\ApiToken;
use App\Models\NotificationWebhookDestination;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Team;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\Notifications\NotificationPublisher;
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
    use ConfirmsActionWithModal;

    public Organization $organization;

    public string $invite_email = '';

    public string $invite_role = 'member';

    public string $token_name = '';

    public ?string $token_expires_at = null;

    /** full | read | deploy | ops — maps to API abilities */
    public string $token_scope = 'full';

    public string $token_allowed_ips_text = '';

    public string $int_hook_name = '';

    public string $int_hook_driver = NotificationWebhookDestination::DRIVER_SLACK;

    public string $int_hook_url = '';

    public ?string $int_hook_site_id = null;

    public bool $int_evt_success = true;

    public bool $int_evt_failed = true;

    public bool $int_evt_skipped = true;

    public bool $int_evt_insight_opened = false;

    public bool $int_evt_insight_resolved = false;

    public bool $deploy_email_notifications_enabled = true;

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
    }

    protected function refreshOrganization(): void
    {
        $this->organization = $this->organization->fresh()
            ->loadCount(['servers', 'sites'])
            ->load([
                'users',
                'teams' => fn ($q) => $q->withCount('users')->with('users'),
                'invitations' => fn ($q) => $q->where('expires_at', '>', now()),
                'apiTokens',
                'notificationWebhookDestinations',
                'sites' => fn ($q) => $q->orderBy('name'),
            ]);
        $this->deploy_email_notifications_enabled = (bool) $this->organization->deploy_email_notifications_enabled;
        $this->syncTeamNames();
    }

    public function updatedDeployEmailNotificationsEnabled(): void
    {
        $this->authorize('update', $this->organization);

        $this->organization->update([
            'deploy_email_notifications_enabled' => $this->deploy_email_notifications_enabled,
        ]);
        audit_log($this->organization, auth()->user(), 'organization.deploy_email_notifications_updated', null, null, [
            'enabled' => $this->deploy_email_notifications_enabled,
        ]);
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Deploy email preferences updated.');
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

        $event = app(NotificationPublisher::class)->publish(
            eventKey: 'organization.invitation.sent',
            subject: $this->organization,
            title: 'Invitation sent',
            body: $email.' was invited to join '.$this->organization->name.'.',
            url: route('organizations.show', $this->organization, absolute: true),
            actor: auth()->user(),
            recipientUsers: $this->organization->users()->wherePivotIn('role', ['owner', 'admin'])->pluck('users.id')->all(),
            metadata: [
                'invitation_id' => $invitation->id,
                'invitation_token' => $invitation->token,
                'email' => $email,
                'role' => $invitation->role,
                'organization_name' => $this->organization->name,
                'inviter_name' => auth()->user()?->name ?? auth()->user()?->email ?? __('Someone'),
            ],
        );
        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification($event));
        audit_log($this->organization, auth()->user(), 'invitation.sent', $invitation);

        $this->reset(['invite_email', 'invite_role']);
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Invitation sent to '.$email);
    }

    public function cancelInvitation(int|string $invitationId): void
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
        $presets = config('api_token_permissions.presets', []);
        $abilities = match ($this->token_scope) {
            'read' => $presets['read'] ?? [],
            'deploy' => $presets['deploy'] ?? [],
            'ops' => $presets['ops'] ?? [],
            default => $presets['full'] ?? ['*'],
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

    public function revokeApiToken(int|string $apiTokenId): void
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

    public function deleteTeam(int|string $teamId): void
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

    public function saveWebhookDestination(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'int_hook_name' => 'required|string|max:120',
            'int_hook_driver' => 'required|string|in:slack,discord,teams',
            'int_hook_url' => 'required|string|url|max:2000',
            'int_hook_site_id' => 'nullable',
        ]);

        $siteId = $this->int_hook_site_id !== null && $this->int_hook_site_id !== ''
            ? (string) $this->int_hook_site_id
            : null;
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
        if ($this->int_evt_insight_opened) {
            $events[] = 'insight_opened';
        }
        if ($this->int_evt_insight_resolved) {
            $events[] = 'insight_resolved';
        }

        NotificationWebhookDestination::query()->create([
            'organization_id' => $this->organization->id,
            'site_id' => $siteId,
            'name' => $this->int_hook_name,
            'driver' => $this->int_hook_driver,
            'webhook_url' => $this->int_hook_url,
            'events' => $events !== [] ? $events : null,
            'enabled' => true,
        ]);

        $this->reset(['int_hook_name', 'int_hook_url', 'int_hook_site_id']);
        $this->int_hook_driver = NotificationWebhookDestination::DRIVER_SLACK;
        $this->int_evt_success = true;
        $this->int_evt_failed = true;
        $this->int_evt_skipped = true;
        $this->int_evt_insight_opened = false;
        $this->int_evt_insight_resolved = false;
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Webhook destination saved.');
    }

    public function deleteWebhookDestination(string $id): void
    {
        $this->authorize('update', $this->organization);
        $hook = $this->organization->notificationWebhookDestinations()->whereKey($id)->firstOrFail();
        $hook->delete();
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Webhook destination removed.');
    }

    public function toggleWebhookDestination(string $id): void
    {
        $this->authorize('update', $this->organization);
        $hook = $this->organization->notificationWebhookDestinations()->whereKey($id)->firstOrFail();
        $hook->update(['enabled' => ! $hook->enabled]);
        $this->refreshOrganization();
    }

    /**
     * @return array<int, string>|null
     */
    protected function parseTokenAllowedIps(string $raw): ?array
    {
        return ApiToken::parseAllowedIpsInput($raw, 'token_allowed_ips_text');
    }

    public function render(): View
    {
        return view('livewire.organizations.show');
    }
}
