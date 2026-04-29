<?php

namespace App\Livewire\Teams;

use App\Livewire\Concerns\ManagesNotificationChannels;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class NotificationChannels extends Component
{
    use ManagesNotificationChannels;

    public Organization $organization;

    public Team $team;

    public function mount(Organization $organization, Team $team): void
    {
        abort_unless($team->organization_id === $organization->id, 404);
        $this->organization = $organization;
        $this->team = $team;
        $this->authorize('viewNotificationChannels', $team);
        $this->syncNotificationChannelTypeDefaults();
    }

    protected function owner(): User|Organization|Team
    {
        return $this->team;
    }

    protected function notificationChannelsViewData(): array
    {
        return [
            'pageTitle' => __('Team notification channels'),
            'intro' => __('Destinations for team-scoped notifications. Team admins and organization admins can manage channels; all team members can view.'),
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $this->organization->name, 'href' => route('organizations.show', $this->organization), 'icon' => 'building-office-2'],
                ['label' => $this->team->name, 'icon' => 'user-group'],
                ['label' => __('Notification channels'), 'icon' => 'bell-alert'],
            ],
            'backUrl' => route('organizations.show', $this->organization),
            'backLabel' => __('Back to organization'),
            'showBulkAssign' => false,
            'currentOrganization' => null,
            'organizationChannels' => collect(),
            'teamChannelGroups' => collect(),
        ];
    }

    public function render(): View
    {
        return $this->renderNotificationChannelsView();
    }
}
