<?php

namespace App\Livewire\Organizations;

use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Activity extends Component
{
    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        abort_unless($organization->hasAdminAccess(auth()->user()), 403);
        $this->organization = $organization;
    }

    public function getAuditLogsProperty(): Collection
    {
        return $this->organization->auditLogs()
            ->with('user')
            ->latest()
            ->limit(100)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.organizations.activity');
    }
}
