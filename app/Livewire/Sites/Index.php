<?php

namespace App\Livewire\Sites;

use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            abort(403, 'Select an organization first.');
        }

        $this->authorize('viewAny', Site::class);

        $serverIds = $org->servers()->pluck('id');

        $sites = Site::query()
            ->whereIn('server_id', $serverIds)
            ->with(['server', 'domains'])
            ->orderByDesc('id')
            ->get();

        return view('livewire.sites.index', [
            'sites' => $sites,
            'organization' => $org,
        ]);
    }
}
