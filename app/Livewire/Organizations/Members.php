<?php

namespace App\Livewire\Organizations;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Members extends Component
{
    use ConfirmsActionWithModal;

    public Organization $organization;

    public string $invite_email = '';

    public string $invite_role = 'member';

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->organization = $organization;
        $this->refreshOrganization();
    }

    protected function refreshOrganization(): void
    {
        $this->organization = $this->organization->fresh()
            ->load([
                'users',
                'invitations' => fn ($q) => $q->where('expires_at', '>', now()),
            ]);
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
            url: route('organizations.members', $this->organization, absolute: true),
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

    public function render(): View
    {
        return view('livewire.organizations.members');
    }
}
