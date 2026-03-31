<?php

namespace App\Livewire\Layout;

use App\Models\Team;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class ContextBreadcrumb extends Component
{
    public static function initials(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        if ($parts === []) {
            return '?';
        }
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[array_key_last($parts)], 0, 1));
    }

    public function switchOrganization(string $organizationId): mixed
    {
        $user = auth()->user();
        $inOrg = $user->organizations()->where('organizations.id', $organizationId)->exists();
        if (! $inOrg) {
            abort(403);
        }
        Session::put('current_organization_id', $organizationId);
        Session::forget('current_team_id');
        Session::flash('success', __('Organization switched.'));

        return $this->redirect(request()->header('Referer', route('dashboard')), navigate: true);
    }

    public function switchTeam(?string $teamId = null): mixed
    {
        $org = auth()->user()->currentOrganization();
        if (! $org || ! $org->hasMember(auth()->user())) {
            abort(403);
        }

        if ($teamId === null || $teamId === '') {
            Session::forget('current_team_id');
        } else {
            $team = Team::query()->whereKey($teamId)->where('organization_id', $org->id)->first();
            if (! $team) {
                abort(403);
            }
            Session::put('current_team_id', $team->id);
        }

        return $this->redirect(request()->header('Referer', route('dashboard')), navigate: true);
    }

    public function render(): View
    {
        $user = auth()->user();
        $organizations = $user->organizations()->orderBy('name')->get();
        $currentOrg = $user->currentOrganization();
        $teams = $currentOrg ? $user->accessibleTeamsForOrganization($currentOrg) : new Collection([]);
        $currentTeam = $user->currentTeam();

        return view('livewire.layout.context-breadcrumb', [
            'organizations' => $organizations,
            'currentOrg' => $currentOrg,
            'teams' => $teams,
            'currentTeam' => $currentTeam,
        ]);
    }
}
