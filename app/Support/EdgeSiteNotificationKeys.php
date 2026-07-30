<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Notification event keys for an Edge site, surfaced on Edge → Alerts.
 * The `edge.` prefix maps these to the Site subscribable in
 * {@see NotificationSubscriptionRules::subscribableClassForEvent}; they are
 * listed under the "edge" category in config/notification_events.php.
 */
final class EdgeSiteNotificationKeys
{
    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_values(array_map(
            static fn (mixed $key): string => (string) $key,
            array_keys((array) config('notification_events.categories.edge.events', [])),
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function eventLabels(): array
    {
        $events = (array) config('notification_events.categories.edge.events', []);

        return array_map(static fn (mixed $label): string => (string) $label, $events);
    }

    /**
     * Groups for {@see resources/views/livewire/partials/notification-channel-matrix.blade.php}.
     *
     * @return list<array{label: string, events: array<string, string>}>
     */
    public static function eventGroups(): array
    {
        $events = self::eventLabels();
        if ($events === []) {
            return [];
        }

        return [
            [
                'label' => (string) __('Edge'),
                'events' => $events,
            ],
        ];
    }
}
