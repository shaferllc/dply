<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Models\NotificationEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class ResourceSummary extends Component
{
    public string $resourceType = '';

    public string $resourceId = '';

    public string $heading = '';

    public ?string $manageUrl = null;

    public bool $showClearConfirm = false;

    public function mount(Model $resource, ?string $heading = null, ?string $manageUrl = null): void
    {
        $this->resourceType = $resource::class;
        $this->resourceId = (string) $resource->getKey();
        $this->heading = $heading ?? __('Recent notifications');
        $this->manageUrl = $manageUrl;
    }

    public function openClearConfirm(): void
    {
        $this->showClearConfirm = true;
    }

    public function closeClearConfirm(): void
    {
        $this->showClearConfirm = false;
    }

    public function clearAll(): void
    {
        $this->showClearConfirm = false;

        if (! Schema::hasTable('notification_events')) {
            return;
        }

        NotificationEvent::query()
            ->forResource($this->resourceType, $this->resourceId)
            ->whereNull('cleared_at')
            ->update([
                'cleared_at' => now(),
                'cleared_by_user_id' => auth()->id(),
            ]);
    }

    public function render(): View
    {
        $tablesReady = Schema::hasTable('notification_events');

        $items = $tablesReady
            ? NotificationEvent::query()
                ->forResource($this->resourceType, $this->resourceId)
                ->whereNull('cleared_at')
                ->latest()
                ->limit(5)
                ->get()
            : collect();

        return view('livewire.notifications.resource-summary', [
            'items' => $items,
            'tablesReady' => $tablesReady,
        ]);
    }
}
