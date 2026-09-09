<?php

namespace App\Livewire\Organizations;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Team;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The People directory: everyone with access to the organization, the role that
 * decides what they can do, and the teams they sit on.
 *
 * Teams used to own a page of their own (`/teams`, removed 2026-08-22). They
 * grant no permissions — a team is a named group of members whose only job is
 * to scope notification channels — so the standalone page duplicated this one's
 * member list, invite flow, pending-invitation list and role vocabulary. Teams
 * are now a filter over this directory and a column on it; the only thing that
 * still has its own page is a team's notification channels.
 */
#[Layout('layouts.app')]
class Members extends Component
{
    use ConfirmsActionWithModal;

    public Organization $organization;

    /**
     * Bound to the modal's :show. The dialog is teleported to <body>, so a
     * live-model keystroke re-renders it and an event-only open snaps shut —
     * state has to say whether it is open.
     */
    public bool $show_invite_modal = false;

    public string $invite_email = '';

    public string $invite_role = 'member';

    /** Team the invite lands on, '' for an org-only invite. */
    public string $invite_team_id = '';

    /** Rail filter: '' for everyone, otherwise a team id (ULID). */
    #[Url(as: 'team', except: '')]
    public string $teamFilter = '';

    /** Name field shared by the create-team and rename-team modal. */
    public string $team_name = '';

    /** Team being renamed, null when the modal is creating. */
    public ?string $editingTeamId = null;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);

        // The route-bound model is already fresh — just eager-load the relations
        // the view needs, rather than re-querying it via refreshOrganization().
        $this->organization = $organization->load($this->relations());
    }

    /** @return array<string, \Closure>|array<int, string> */
    protected function relations(): array
    {
        return [
            'users',
            'invitations' => fn ($q) => $q->where('expires_at', '>', now())->with('team'),
            'teams.users',
        ];
    }

    protected function refreshOrganization(): void
    {
        $this->organization = $this->organization->fresh()->load($this->relations());
    }

    /**
     * Members shown by the directory, narrowed to the rail's team when one is
     * selected. Invitations are filtered the same way in the view.
     */
    public function members(): Collection
    {
        if ($this->teamFilter === '') {
            return $this->organization->users;
        }

        $team = $this->organization->teams->firstWhere('id', $this->teamFilter);

        return $team ? $this->organization->users->whereIn('id', $team->users->pluck('id')) : collect();
    }

    public function inviteMember(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'invite_email' => 'required|email',
            'invite_role' => 'nullable|string|in:admin,member,deployer',
            'invite_team_id' => 'nullable|string',
        ]);

        $email = strtolower($this->invite_email);
        $team = $this->invite_team_id === ''
            ? null
            : $this->organization->teams()->findOrFail($this->invite_team_id);

        // Someone already in the organization needs no invitation. With a team
        // chosen they are attached to it on the spot; without one there is
        // nothing left to do but say so.
        $existingMember = $this->organization->users()->where('users.email', $email)->first();
        if ($existingMember) {
            if (! $team) {
                throw ValidationException::withMessages(['invite_email' => __('That user is already a member.')]);
            }
            if ($team->users()->where('user_id', $existingMember->id)->exists()) {
                throw ValidationException::withMessages(['invite_email' => __('That member is already on this team.')]);
            }

            $team->users()->attach($existingMember->id, ['role' => 'member']);
            audit_log($this->organization, auth()->user(), 'team.member_added', $team, null, [
                'team_id' => (string) $team->id,
                'user_id' => $existingMember->id,
            ]);

            $this->closeInviteModal();
            $this->refreshOrganization();
            $this->dispatch('notify', message: $existingMember->name.' was already a member — added to '.$team->name.'.');

            return;
        }

        // One pending invite per address per org (enforced by a unique index).
        if ($this->organization->invitations()->where('email', $email)->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages([
                'invite_email' => __('An invitation has already been sent to that address. Cancel it below to re-send it.'),
            ]);
        }

        $maxMembers = $this->organization->effectiveMemberSeatCap();
        if ($maxMembers !== null) {
            $current = $this->organization->users()->count();
            $pending = $this->organization->invitations()->where('expires_at', '>', now())->count();
            if ($current + $pending >= $maxMembers) {
                throw ValidationException::withMessages([
                    'invite_email' => __('This organization has reached its member limit (:max).', ['max' => $maxMembers]),
                ]);
            }
        }

        $actor = auth()->user();
        $invitation = OrganizationInvitation::createFor(
            $this->organization,
            $email,
            $this->invite_role ?: 'member',
            $actor,
            $team,
        );

        $event = app(NotificationPublisher::class)->publish(
            eventKey: 'organization.invitation.sent',
            subject: $this->organization,
            title: 'Invitation sent',
            body: $team
                ? $email.' was invited to join '.$this->organization->name.' on the team '.$team->name.'.'
                : $email.' was invited to join '.$this->organization->name.'.',
            url: route('organizations.members', $this->organization, absolute: true),
            actor: $actor,
            recipientUsers: $this->organization->users()->wherePivotIn('role', ['owner', 'admin'])->pluck('users.id')->all(),
            metadata: array_filter([
                'invitation_id' => $invitation->id,
                'invitation_token' => $invitation->token,
                'email' => $email,
                'role' => $invitation->role,
                'organization_name' => $this->organization->name,
                'team_name' => $team?->name,
                'inviter_name' => $actor->name !== '' ? $actor->name : ($actor->email !== '' ? $actor->email : __('Someone')),
            ], fn ($v) => $v !== null),
        );
        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification($event));
        audit_log($this->organization, auth()->user(), 'invitation.sent', $invitation);

        $this->closeInviteModal();
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Invitation sent to '.$email);
    }

    public function openInviteModal(): void
    {
        $this->authorize('update', $this->organization);

        $this->invite_email = '';
        $this->invite_role = 'member';
        // The rail's team is the one you're looking at, so default the invite to it.
        $this->invite_team_id = $this->teamFilter;
        $this->resetValidation(['invite_email', 'invite_role', 'invite_team_id']);
        $this->show_invite_modal = true;
        $this->dispatch('open-modal', 'invite-member-modal');
    }

    /**
     * Type-ahead over every dply account, so an existing user can be invited by
     * name instead of a remembered address. Scoped only by "not already in this
     * org", and reachable only from the admin-gated invite modal.
     *
     * Note this is an account-enumeration surface by design (asked for): a
     * partial name confirms whether someone has a dply account. Colleagues rank
     * first so the common case stays at the top.
     *
     * @return Collection<int, User>
     */
    public function inviteSuggestions(): Collection
    {
        $needle = trim($this->invite_email);
        if (mb_strlen($needle) < 2) {
            return collect();
        }

        $orgIds = auth()->user()->organizations()->pluck('organizations.id');
        $memberIds = $this->organization->users->pluck('id');

        return User::query()
            ->whereNotIn('users.id', $memberIds)
            // ilike, not like: Postgres LIKE is case-sensitive, so "lakin" would
            // never match "Judah Lakin".
            ->where(fn ($q) => $q
                ->where('name', 'ilike', '%'.$needle.'%')
                ->orWhere('email', 'ilike', '%'.$needle.'%'))
            ->withExists(['organizations as shares_org' => fn ($q) => $q->whereIn('organizations.id', $orgIds)])
            ->orderByDesc('shares_org')
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    public function pickInviteSuggestion(string $email): void
    {
        $this->invite_email = $email;
    }

    public function closeInviteModal(): void
    {
        $this->invite_email = '';
        $this->invite_role = 'member';
        $this->invite_team_id = '';
        $this->resetValidation(['invite_email', 'invite_role', 'invite_team_id']);
        $this->show_invite_modal = false;
        $this->dispatch('close-modal', 'invite-member-modal');
    }

    /**
     * Roles assignable through invites. Owner is tied to org ownership and is not granted via invitation.
     *
     * @return array<string, string>
     */
    public function inviteableRoles(): array
    {
        return [
            'member' => __('Member'),
            'admin' => __('Admin'),
            'deployer' => __('Deployer'),
        ];
    }

    public function promptCancelInvitation(string $invitationId): void
    {
        $this->openConfirmActionModal(
            'cancelInvitation',
            [$invitationId],
            __('Cancel invitation'),
            __('Cancel this invitation?'),
            __('Cancel invitation'),
            true,
        );
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

    // ── Teams ────────────────────────────────────────────────────────────────

    public function selectTeam(string $teamId = ''): void
    {
        $this->teamFilter = $teamId !== '' && $this->organization->teams->contains('id', $teamId) ? $teamId : '';
    }

    /** Open the shared team modal — creating when $teamId is null, renaming otherwise. */
    public function openTeamModal(?string $teamId = null): void
    {
        if ($teamId === null) {
            $this->authorize('create', [Team::class, $this->organization]);
            $this->editingTeamId = null;
            $this->team_name = '';
        } else {
            $team = $this->organization->teams()->findOrFail($teamId);
            $this->authorize('update', $team);
            $this->editingTeamId = (string) $team->id;
            $this->team_name = $team->name;
        }

        $this->resetValidation(['team_name']);
        $this->dispatch('open-modal', 'team-modal');
    }

    public function closeTeamModal(): void
    {
        $this->editingTeamId = null;
        $this->team_name = '';
        $this->resetValidation(['team_name']);
        $this->dispatch('close-modal', 'team-modal');
    }

    public function saveTeam(): void
    {
        $this->validate(['team_name' => 'required|string|max:255']);

        if ($this->editingTeamId !== null) {
            $team = $this->organization->teams()->findOrFail($this->editingTeamId);
            $this->authorize('update', $team);

            $oldName = $team->name;
            $team->update(['name' => $this->team_name]);
            audit_log($this->organization, auth()->user(), 'team.updated', $team, ['name' => $oldName], ['name' => $team->name]);

            $this->closeTeamModal();
            $this->refreshOrganization();
            $this->dispatch('notify', message: 'Team renamed.');

            return;
        }

        $this->authorize('create', [Team::class, $this->organization]);

        $team = $this->organization->teams()->create([
            'name' => $this->team_name,
            'slug' => $this->uniqueTeamSlug($this->team_name),
        ]);
        audit_log($this->organization, auth()->user(), 'team.created', $team);

        $this->closeTeamModal();
        $this->refreshOrganization();
        // Drop straight into the new (empty) team so the next click adds someone to it.
        $this->teamFilter = (string) $team->id;
        $this->dispatch('notify', message: 'Team created.');
    }

    protected function uniqueTeamSlug(string $name): string
    {
        $base = Str::slug(Str::limit($name, 50));
        $slug = $base;
        $i = 0;
        while (Team::where('organization_id', $this->organization->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    public function promptDeleteTeam(string $teamId): void
    {
        $team = $this->organization->teams->firstWhere('id', $teamId);

        $this->openConfirmActionModal(
            'deleteTeam',
            [$teamId],
            __('Delete team'),
            __('Delete the team “:team”? Its members keep their access — only the grouping and its notification channels go away.', [
                'team' => (string) $team?->name,
            ]),
            __('Delete'),
            true,
        );
    }

    public function deleteTeam(int|string $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('delete', $team);

        audit_log($this->organization, auth()->user(), 'team.deleted', $team, ['name' => $team->name], null);
        $team->delete();

        if ($this->teamFilter === (string) $teamId) {
            $this->teamFilter = '';
        }

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Team removed.');
    }

    /**
     * Add or drop one person's membership of one team — the chip in the Teams
     * column. Deliberately not confirmed: it is a one-click toggle that grants
     * no access and is undone by clicking again.
     */
    /**
     * Removing someone from a team is one click on a chip, so it asks first —
     * adding still goes straight through (it is undone by the same chip).
     */
    public function promptRemoveFromTeam(string $teamId, string $userId): void
    {
        $team = $this->organization->teams->firstWhere('id', $teamId);
        $user = User::find($userId);

        $this->openConfirmActionModal(
            'toggleTeamMembership',
            [$teamId, $userId],
            __('Remove from team'),
            __('Remove :name from “:team”? They keep their organization access — only the team membership and anything scoped to it go away.', [
                'name' => (string) $user?->name,
                'team' => (string) $team?->name,
            ]),
            __('Remove'),
            true,
        );
    }

    public function toggleTeamMembership(string $teamId, string $userId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);

        $user = User::find($userId);
        if (! $user || ! $this->organization->hasMember($user)) {
            $this->dispatch('notify', message: 'That person is not an organization member.');

            return;
        }

        if ($team->users()->where('user_id', $user->id)->exists()) {
            $team->users()->detach($user->id);
            audit_log($this->organization, auth()->user(), 'team.member_removed', $team, [
                'team_id' => (string) $team->id,
                'user_id' => (string) $user->id,
            ], null);
            $message = $user->name.' removed from '.$team->name.'.';
        } else {
            $team->users()->attach($user->id, ['role' => 'member']);
            audit_log($this->organization, auth()->user(), 'team.member_added', $team, null, [
                'team_id' => (string) $team->id,
                'user_id' => (string) $user->id,
            ]);
            $message = $user->name.' added to '.$team->name.'.';
        }

        $this->refreshOrganization();
        $this->dispatch('notify', message: $message);
    }

    public function render(): View
    {
        return view('livewire.organizations.members');
    }
}
