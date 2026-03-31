<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ManagesNotificationChannels;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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
                ['label' => __('Dashboard'), 'url' => route('dashboard')],
                ['label' => __('Profile'), 'url' => route('profile.edit')],
                ['label' => __('Notification channels'), 'url' => null],
            ],
            'showBulkAssign' => true,
        ];
    }

    public function render(): View
    {
        return $this->renderNotificationChannelsView();
    }
}
