<?php

namespace App\Livewire\Organizations;

use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->organization = $organization;
        $this->refreshOrganization();
    }

    protected function refreshOrganization(): void
    {
        $this->organization = $this->organization->fresh()
            ->loadCount(['servers', 'sites'])
            ->load([
                'users',
                'teams',
                'invitations' => fn ($q) => $q->where('expires_at', '>', now()),
                'apiTokens',
                'notificationWebhookDestinations',
            ]);
    }

    public function render(): View
    {
        return view('livewire.organizations.show');
    }
}
