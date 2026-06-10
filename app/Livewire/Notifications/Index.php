<?php

namespace App\Livewire\Notifications;

use App\Models\NotificationInboxItem;
use App\Support\NotificationTablesReady;
use Illuminate\Contracts\View\View;
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
                'totalCount' => 0,
                'attentionCount' => 0,
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

        $base = fn () => NotificationInboxItem::query()->where('user_id', auth()->id());

        return view('livewire.notifications.index', [
            'items' => $query->limit(50)->get(),
            'unreadCount' => $base()->whereNull('read_at')->count(),
            'totalCount' => $base()->count(),
            'attentionCount' => $base()
                ->whereNull('read_at')
                ->whereHas('event', fn ($q) => $q->whereIn('severity', ['warning', 'critical', 'error', 'danger']))
                ->count(),
            'notificationsReady' => true,
        ]);
    }

    protected function notificationTablesReady(): bool
    {
        return NotificationTablesReady::all();
    }
}
