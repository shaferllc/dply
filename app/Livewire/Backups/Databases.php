<?php

namespace App\Livewire\Backups;

use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Databases extends Component
{
    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            abort(403, 'Select an organization first.');
        }

        $this->authorize('viewAny', Site::class);

        $serverIds = $org->servers()->pluck('id');

        /** @var Collection<int, Site> $sites */
        $sites = Site::query()
            ->whereIn('server_id', $serverIds)
            ->with('server')
            ->orderBy('name')
            ->get();

        return view('livewire.backups.databases', [
            'organization' => $org,
            'sites' => $sites,
        ]);
    }
}
