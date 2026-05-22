<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Models\NotificationEvent;
use App\Services\Insights\InsightsNotificationDispatcher;
use App\Support\NotificationTablesReady;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class ResourceSummary extends Component
{
    /**
     * Event keys whose notification_events rows are intentionally NOT
     * surfaced in this widget. Insights live on /servers/{id}/insights with
     * their own detail modal + run-fix tracking; duplicating them in the
     * resource-summary stream creates noise on every page that includes
     * this component (Project show, Server → Databases, Server → Services).
     *
     * Suppression is widget-only: InsightsNotificationDispatcher still
     * publishes the event, so channel subscribers (Slack / email /
     * webhook) keep receiving routed copies.
     *
     * @var list<string>
     */
    private const SUPPRESSED_EVENT_KEYS = [
        InsightsNotificationDispatcher::EVENT_KEY, // 'server.insights_alerts'
    ];

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

        if (! NotificationTablesReady::has('notification_events')) {
            return;
        }

        NotificationEvent::query()
            ->forResource($this->resourceType, $this->resourceId)
            ->whereNull('cleared_at')
            // Don't sweep rows the user couldn't see — clearing on their
            // behalf would silently mark insight notifications as
            // acknowledged in audit data they didn't actually review.
            ->whereNotIn('event_key', self::SUPPRESSED_EVENT_KEYS)
            ->update([
                'cleared_at' => now(),
                'cleared_by_user_id' => auth()->id(),
            ]);
    }

    public function render(): View
    {
        $tablesReady = NotificationTablesReady::has('notification_events');

        $items = $tablesReady
            ? NotificationEvent::query()
                ->forResource($this->resourceType, $this->resourceId)
                ->whereNull('cleared_at')
                ->whereNotIn('event_key', self::SUPPRESSED_EVENT_KEYS)
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
