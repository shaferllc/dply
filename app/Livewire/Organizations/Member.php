<?php

namespace App\Livewire\Organizations;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One member, in the context of one organization: role, teams, tokens, and what
 * they have done here. Read-only — role and team changes still happen on the
 * People directory, which is where the bulk edits belong.
 */
#[Layout('layouts.app')]
class Member extends Component
{
    public Organization $organization;

    public User $user;

    public function mount(Organization $organization, User $user): void
    {
        $this->authorize('view', $organization);

        // A profile is only a profile *here* — someone outside the org has no
        // page in it, and probing for one should not confirm they exist.
        abort_unless($organization->hasMember($user), 404);

        $this->organization = $organization;
        $this->user = $user;
    }

    public function role(): string
    {
        return (string) ($this->organization->users->firstWhere('id', $this->user->id)?->pivot->role ?? 'member');
    }

    /**
     * @return Collection<int, Team>
     */
    public function teams(): Collection
    {
        return $this->user->teams()
            ->where('teams.organization_id', $this->organization->id)
            ->orderBy('teams.name')
            ->get();
    }

    /**
     * @return Collection<int, ApiToken>
     */
    public function tokens(): Collection
    {
        return ApiToken::query()
            ->where('organization_id', $this->organization->id)
            ->where('user_id', $this->user->id)
            ->orderByRaw('last_used_at desc nulls last')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function recentActivity(): Collection
    {
        return AuditLog::query()
            ->where('organization_id', $this->organization->id)
            ->where('user_id', $this->user->id)
            ->latest()
            ->limit(10)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.organizations.member', [
            'role' => $this->role(),
            'teams' => $this->teams(),
            'tokens' => $this->tokens(),
            'activity' => $this->recentActivity(),
        ]);
    }
}
