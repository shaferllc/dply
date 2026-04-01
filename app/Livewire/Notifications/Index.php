<?php

namespace App\Livewire\Notifications;

use App\Models\NotificationInboxItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $filter = 'unread';

    public function markAsRead(string $itemId): void
    {
        if (! $this->notificationTablesReady()) {
            return;
        }

        NotificationInboxItem::query()
            ->where('user_id', auth()->id())
            ->whereKey($itemId)
            ->update(['read_at' => now()]);
    }

    public function markAllAsRead(): void
    {
        if (! $this->notificationTablesReady()) {
            return;
        }

        NotificationInboxItem::query()
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function render(): View
    {
        if (! $this->notificationTablesReady()) {
            return view('livewire.notifications.index', [
                'items' => collect(),
                'unreadCount' => 0,
                'notificationsReady' => false,
            ]);
        }

        $query = NotificationInboxItem::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->with('event');

        if ($this->filter === 'unread') {
            $query->whereNull('read_at');
        }

        return view('livewire.notifications.index', [
            'items' => $query->limit(50)->get(),
            'unreadCount' => NotificationInboxItem::query()
                ->where('user_id', auth()->id())
                ->whereNull('read_at')
                ->count(),
            'notificationsReady' => true,
        ]);
    }

    protected function notificationTablesReady(): bool
    {
        return Schema::hasTable('notification_inbox_items')
            && Schema::hasTable('notification_events');
    }
}
