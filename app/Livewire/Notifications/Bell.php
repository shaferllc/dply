<?php

namespace App\Livewire\Notifications;

use App\Models\NotificationInboxItem;
use App\Support\NotificationTablesReady;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The header notification bell — an UNREAD QUEUE. The dropdown lists only unread
 * items; "Clear all" (mark all read) empties it to "all caught up". Read history
 * + Saved live on the full inbox ({@see Index}). Extracted from the static Blade
 * in site-header so it can clear / open / star with live badge updates.
 */
class Bell extends Component
{
    /** How many unread items to show in the dropdown. */
    private const LIMIT = 8;

    /** Severities that count as "alerts" for the Alerts-only quick filter. */
    private const ALERT_SEVERITIES = ['warning', 'critical', 'error', 'danger'];

    /** Selected event categories (multi-select; empty = all categories). @var array<int, string> */
    public array $categoryFilters = [];

    /** Filter the unread list to warning/critical/error/danger only. */
    public bool $alertsOnly = false;

    /** Toggle a category in/out of the active multi-select set. */
    public function toggleCategory(string $category): void
    {
        if (in_array($category, $this->categoryFilters, true)) {
            $this->categoryFilters = array_values(array_diff($this->categoryFilters, [$category]));
        } else {
            $this->categoryFilters[] = $category;
        }
    }

    public function toggleAlertsOnly(): void
    {
        $this->alertsOnly = ! $this->alertsOnly;
    }

    public function resetFilters(): void
    {
        $this->categoryFilters = [];
        $this->alertsOnly = false;
    }

    public function markAllAsRead(): void
    {
        if (! $this->ready()) {
            return;
        }

        $this->base()->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Mark-read-on-click, then navigate to the item's deep link. */
    public function openItem(string $itemId)
    {
        if (! $this->ready()) {
            return null;
        }

        $item = $this->base()->whereKey($itemId)->first();
        if ($item === null) {
            return null;
        }

        if ($item->read_at === null) {
            $item->forceFill(['read_at' => now()])->save();
        }

        return redirect()->to($item->ctaUrl() ?: route('notifications.index'));
    }

    /** Star toggle from the bell, so a passing alert can be kept before clearing. */
    public function toggleSaved(string $itemId): void
    {
        if (! $this->ready()) {
            return;
        }

        $item = $this->base()->whereKey($itemId)->first();
        if ($item === null) {
            return;
        }

        $item->forceFill(['saved_at' => $item->saved_at ? null : now()])->save();
    }

    private function ready(): bool
    {
        return auth()->check() && NotificationTablesReady::all();
    }

    private function base()
    {
        return NotificationInboxItem::query()->where('user_id', auth()->id());
    }

    public function render(): View
    {
        if (! $this->ready()) {
            return view('livewire.notifications.bell', [
                'ready' => false,
                'unreadCount' => 0,
                'items' => collect(),
                'categories' => collect(),
            ]);
        }

        // Categories present among the user's UNREAD items, for the filter chips.
        $categories = $this->base()
            ->whereNull('read_at')
            ->join('notification_events', 'notification_events.id', '=', 'notification_inbox_items.notification_event_id')
            ->whereNotNull('notification_events.category')
            ->distinct()
            ->orderBy('notification_events.category')
            ->pluck('notification_events.category');

        $items = $this->base()->whereNull('read_at')->with('event');

        // Keep only valid, still-present categories so a stale filter (its last
        // item just got read/cleared) doesn't silently hide everything.
        $this->categoryFilters = array_values(array_intersect($this->categoryFilters, $categories->all()));

        if ($this->categoryFilters !== []) {
            $items->whereHas('event', fn ($q) => $q->whereIn('category', $this->categoryFilters));
        }

        if ($this->alertsOnly) {
            $items->whereHas('event', fn ($q) => $q->whereIn('severity', self::ALERT_SEVERITIES));
        }

        return view('livewire.notifications.bell', [
            'ready' => true,
            // Badge stays the true unread total, independent of the filter.
            'unreadCount' => $this->base()->whereNull('read_at')->count(),
            'items' => $items->latest()->limit(self::LIMIT)->get(),
            'categories' => $categories,
        ]);
    }
}
