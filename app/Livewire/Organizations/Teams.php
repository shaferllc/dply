<?php

namespace App\Livewire\Organizations;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Teams extends Component
{
    use ConfirmsActionWithModal;

    public Organization $organization;

    public string $team_name = '';

    /** @var array<int, string> team id => name for inline edit */
    public array $teamNames = [];

    /** @var array<int, int> team id => user id for "add member" dropdown */
    public array $addMemberSelected = [];

    /** When set, the save-team-name modal is shown for this team (ULID). */
    public ?string $saveTeamNameModalTeamId = null;

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
                'teams' => fn ($q) => $q->withCount('users')->with('users'),
            ]);
        $this->syncTeamNames();
    }

    protected function syncTeamNames(): void
    {
        $this->teamNames = $this->organization->teams->keyBy('id')->map(fn ($t) => $t->name)->all();
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
        $this->dispatch('close-modal', 'create-team-modal');
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Team created.');
    }

    public function openCreateTeamModal(): void
    {
        $this->authorize('create', [Team::class, $this->organization]);

        $this->team_name = '';
        $this->resetValidation(['team_name']);
        $this->dispatch('open-modal', 'create-team-modal');
    }

    public function closeCreateTeamModal(): void
    {
        $this->team_name = '';
        $this->resetValidation(['team_name']);
        $this->dispatch('close-modal', 'create-team-modal');
    }

    public function promptDeleteTeam(string $teamId): void
    {
        $this->openConfirmActionModal(
            'deleteTeam',
            [$teamId],
            __('Delete team'),
            __('Remove this team?'),
            __('Delete'),
            true,
        );
    }

    public function promptSaveTeamNameOnBlur(string $teamId): void
    {
        $team = $this->organization->teams->firstWhere('id', $teamId);
        if (! $team) {
            return;
        }

        $new = trim((string) ($this->teamNames[$teamId] ?? ''));
        if ($new === $team->name) {
            return;
        }
        if ($new === '') {
            $this->teamNames[$teamId] = $team->name;

            return;
        }

        $this->saveTeamNameModalTeamId = $teamId;
        $this->dispatch('open-modal', 'save-team-name-modal');
    }

    public function confirmSaveTeamName(): void
    {
        if ($this->saveTeamNameModalTeamId === null) {
            return;
        }

        $id = $this->saveTeamNameModalTeamId;
        $this->updateTeam($id);
        $this->saveTeamNameModalTeamId = null;
        $this->dispatch('close-modal', 'save-team-name-modal');
    }

    public function cancelSaveTeamName(): void
    {
        if ($this->saveTeamNameModalTeamId === null) {
            return;
        }

        $id = $this->saveTeamNameModalTeamId;
        $team = $this->organization->teams->firstWhere('id', $id);
        if ($team) {
            $this->teamNames[$id] = $team->name;
        }
        $this->resetValidation(['teamNames.'.$id]);
        $this->saveTeamNameModalTeamId = null;
        $this->dispatch('close-modal', 'save-team-name-modal');
    }

    public function updateTeam(int|string $teamId): void
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

    public function addTeamMember(int|string $teamId): void
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

    public function promptRemoveTeamMember(string $teamId, int $userId): void
    {
        $team = $this->organization->teams->firstWhere('id', $teamId);
        $member = $team ? User::find($userId) : null;

        $this->openConfirmActionModal(
            'removeTeamMember',
            [$teamId, $userId],
            __('Remove from team'),
            __('Remove :member from the team “:team”?', [
                'member' => $member?->name ?? __('this member'),
                'team' => $team?->name ?? __('this team'),
            ]),
            __('Remove'),
            true,
        );
    }

    public function removeTeamMember(int|string $teamId, int $userId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);
        $team->users()->detach($userId);

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Member removed from team.');
    }

    public function render(): View
    {
        return view('livewire.organizations.teams');
    }
}
