<?php

namespace App\Livewire\Organizations;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $search = '';

    public function switchOrganization(string $organizationId): mixed
    {
        $inOrg = auth()->user()->organizations()->where('organizations.id', $organizationId)->exists();
        if (! $inOrg) {
            abort(403);
        }
        Session::put('current_organization_id', $organizationId);
        Session::forget('current_team_id');
        Session::flash('success', 'Organization switched.');

        return $this->redirect(request()->header('Referer', route('organizations.index')), navigate: true);
    }

    public function render(): View
    {
        $all = auth()->user()->organizations()->withCount(['users', 'teams', 'servers', 'sites', 'workspaces'])->get();

        $needle = trim($this->search);
        $organizations = $needle === ''
            ? $all
            : $all->filter(fn ($org) => str_contains(mb_strtolower((string) $org->name), mb_strtolower($needle)))->values();

        return view('livewire.organizations.index', [
            'organizations' => $organizations,
            'allOrganizations' => $all,
        ]);
    }
}
