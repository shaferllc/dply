<?php

namespace App\Livewire\Organizations;

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

    public function mount(Organization $organization): void
    {
        $this->organization = $organization;
        $this->authorize('viewNotificationChannels', $organization);
        $this->syncNotificationChannelTypeDefaults();
    }

    protected function owner(): User|Organization|Team
    {
        return $this->organization;
    }

    protected function notificationChannelsViewData(): array
    {
        return [
            'pageTitle' => __('Organization notification channels'),
            'intro' => __('Webhooks and chat destinations for this organization. Only organization admins can add or edit channels.'),
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $this->organization->name, 'href' => route('organizations.show', $this->organization), 'icon' => 'building-office-2'],
                ['label' => __('Notification channels'), 'icon' => 'bell-alert'],
            ],
            'backUrl' => null,
            'backLabel' => null,
            'organization' => $this->organization,
            'useOrgShell' => true,
            'orgShellSection' => 'notifications',
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
