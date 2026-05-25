<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeSiteMember;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Per-site team Members tab (P12). Invite existing org members by
 * email and grant them viewer/deployer/admin on this Edge site.
 *
 * @property Site $site
 * @property Server $server
 */
class Members extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;

    #[Validate('required|email|max:255')]
    public string $inviteEmail = '';

    #[Validate('required|in:viewer,deployer,admin')]
    public string $inviteRole = EdgeSiteMember::ROLE_VIEWER;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
    }

    public function addMember(): void
    {
        $this->authorize('manageMembers', $this->site);
        $this->validate();

        $org = $this->site->organization;
        if ($org === null) {
            $this->toastError(__('This site has no organization.'));

            return;
        }

        $user = User::query()->where('email', $this->inviteEmail)->first();
        if ($user === null) {
            $this->addError('inviteEmail', __('No user with that email is in this organization.'));

            return;
        }

        if (! $org->users()->whereKey($user->id)->exists()) {
            $this->addError('inviteEmail', __('No user with that email is in this organization.'));

            return;
        }

        EdgeSiteMember::query()->updateOrCreate(
            ['site_id' => $this->site->id, 'user_id' => $user->id],
            ['role' => $this->inviteRole, 'invited_by_user_id' => auth()->id()],
        );

        audit_log($org, auth()->user(), 'site.edge.member.added', $this->site, null, [
            'user_id' => $user->id,
            'role' => $this->inviteRole,
        ]);

        $this->reset(['inviteEmail', 'inviteRole']);
        $this->inviteRole = EdgeSiteMember::ROLE_VIEWER;
        $this->toastSuccess(__(':name added as :role.', ['name' => $user->name ?: $user->email, 'role' => $this->inviteRole]));
    }

    public function updateRole(string $memberId, string $role): void
    {
        $this->authorize('manageMembers', $this->site);

        if (! EdgeSiteMember::isValidRole($role)) {
            return;
        }

        $member = EdgeSiteMember::query()
            ->where('id', $memberId)
            ->where('site_id', $this->site->id)
            ->first();

        if ($member === null || $member->role === $role) {
            return;
        }

        $previous = $member->role;
        $member->update(['role' => $role]);

        audit_log(
            $this->site->organization,
            auth()->user(),
            'site.edge.member.role_changed',
            $this->site,
            ['role' => $previous, 'user_id' => $member->user_id],
            ['role' => $role, 'user_id' => $member->user_id],
        );
        $this->toastSuccess(__('Role updated.'));
    }

    public function removeMember(string $memberId): void
    {
        $this->authorize('manageMembers', $this->site);

        $member = EdgeSiteMember::query()
            ->where('id', $memberId)
            ->where('site_id', $this->site->id)
            ->first();

        if ($member === null) {
            return;
        }

        $payload = ['user_id' => $member->user_id, 'role' => $member->role];
        $member->delete();

        audit_log($this->site->organization, auth()->user(), 'site.edge.member.removed', $this->site, $payload, null);
        $this->toastSuccess(__('Member removed.'));
    }

    public function render(): View
    {
        $members = EdgeSiteMember::query()
            ->with(['user:id,name,email', 'invitedBy:id,name,email'])
            ->where('site_id', $this->site->id)
            ->orderBy('created_at')
            ->get();

        return view('livewire.sites.edge.workspace.members', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-members'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'members' => $members,
                'roleOptions' => $this->roleOptions(),
                'canManage' => auth()->user()?->can('manageMembers', $this->site) ?? false,
            ],
        ));
    }

    /**
     * @return Collection<int, array{value: string, label: string, hint: string}>
     */
    private function roleOptions(): Collection
    {
        return collect([
            ['value' => EdgeSiteMember::ROLE_VIEWER, 'label' => __('Viewer'), 'hint' => __('Read deploys, logs, analytics.')],
            ['value' => EdgeSiteMember::ROLE_DEPLOYER, 'label' => __('Deployer'), 'hint' => __('Trigger deploys, rollback, edit env.')],
            ['value' => EdgeSiteMember::ROLE_ADMIN, 'label' => __('Admin'), 'hint' => __('Full control including domains and members.')],
        ]);
    }
}
