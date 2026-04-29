<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ManagesNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class NotificationChannels extends Component
{
    use ManagesNotificationChannels;

    public function mount(): void
    {
        $this->authorize('viewNotificationChannels', Auth::user());
        $this->syncNotificationChannelTypeDefaults();
    }

    protected function owner(): User|Organization|Team
    {
        return Auth::user();
    }

    protected function notificationChannelsViewData(): array
    {
        return [
            'pageTitle' => __('Notification channels'),
            'intro' => __('Define destinations for alerts and product notifications tied to your account. Org and team channels are managed from their respective settings.'),
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Profile'), 'href' => route('profile.edit'), 'icon' => 'user-circle'],
                ['label' => __('Notification channels'), 'icon' => 'bell-alert'],
            ],
            'showBulkAssign' => true,
        ];
    }

    public function render(): View
    {
        $user = Auth::user();
        $currentOrganization = $user?->currentOrganization();
        $organizationChannels = collect();

        if ($currentOrganization instanceof Organization && Gate::allows('viewNotificationChannels', $currentOrganization)) {
            $organizationChannels = $currentOrganization->notificationChannels()
                ->withCount('subscriptions')
                ->orderBy('label')
                ->get();
        }

        $teamChannels = $user
            ? $user->accessibleTeamsForOrganization($currentOrganization)
                ->filter(fn (Team $team) => Gate::allows('viewNotificationChannels', $team))
                ->map(fn (Team $team) => [
                    'team' => $team,
                    'channels' => $team->notificationChannels()
                        ->withCount('subscriptions')
                        ->orderBy('label')
                        ->get(),
                ])
                ->filter(fn (array $entry) => $entry['channels']->isNotEmpty())
                ->values()
            : collect();

        return view('livewire.settings.notification-channels', array_merge([
            'backUrl' => null,
            'backLabel' => null,
            'useOrgShell' => false,
            'organization' => null,
            'orgShellSection' => 'notifications',
        ], $this->notificationChannelsViewData(), [
            'channels' => $this->channels,
            'canManage' => $this->canManage(),
            'types' => NotificationChannel::typesForUi(),
            'typesForEdit' => NotificationChannel::typesForUi($this->editing_id ? $this->edit_type : null),
            'currentOrganization' => $currentOrganization,
            'organizationChannels' => $organizationChannels,
            'teamChannelGroups' => $teamChannels,
        ]));
    }
}
