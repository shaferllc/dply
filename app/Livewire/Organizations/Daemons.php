<?php

declare(strict_types=1);

namespace App\Livewire\Organizations;

use App\Models\Organization;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Daemons extends Component
{
    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->organization = $organization;
    }

    public function render(): View
    {
        $servers = Server::query()
            ->where('organization_id', $this->organization->id)
            ->withCount(['supervisorPrograms'])
            ->orderBy('name')
            ->get();

        return view('livewire.organizations.daemons', [
            'servers' => $servers,
        ]);
    }
}
