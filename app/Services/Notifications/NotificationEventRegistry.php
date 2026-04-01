<?php

namespace App\Services\Notifications;

use App\Support\ServerDatabaseNotificationKeys;
use App\Support\ServerSystemdServiceNotificationKeys;

class NotificationEventRegistry
{
    /**
     * @return array{key: string, label: string, category: string|null, severity: string, supports_in_app: bool, supports_email: bool, supports_webhook: bool}
     */
    public function definition(string $eventKey): array
    {
        $configured = config('notification_events.categories', []);

        foreach ($configured as $categoryKey => $category) {
            $events = $category['events'] ?? [];
            if (! is_array($events) || ! array_key_exists($eventKey, $events)) {
                continue;
            }

            return [
                'key' => $eventKey,
                'label' => (string) $events[$eventKey],
                'category' => (string) $categoryKey,
                'severity' => str_contains($eventKey, 'monitor') || str_contains($eventKey, 'alerts') ? 'warning' : 'info',
                'supports_in_app' => true,
                'supports_email' => false,
                'supports_webhook' => true,
            ];
        }

        if (in_array($eventKey, [
            ServerDatabaseNotificationKeys::eventKey('created'),
            ServerDatabaseNotificationKeys::eventKey('removed'),
        ], true)) {
            return [
                'key' => $eventKey,
                'label' => $eventKey === ServerDatabaseNotificationKeys::eventKey('created') ? 'Database created' : 'Database removed',
                'category' => 'server',
                'severity' => 'info',
                'supports_in_app' => true,
                'supports_email' => false,
                'supports_webhook' => true,
            ];
        }

        if (ServerSystemdServiceNotificationKeys::isValidDynamicEventKey($eventKey)) {
            return [
                'key' => $eventKey,
                'label' => 'Service alert',
                'category' => 'server',
                'severity' => 'warning',
                'supports_in_app' => true,
                'supports_email' => false,
                'supports_webhook' => true,
            ];
        }

        return [
            'key' => $eventKey,
            'label' => $eventKey,
            'category' => null,
            'severity' => 'info',
            'supports_in_app' => true,
            'supports_email' => false,
            'supports_webhook' => true,
        ];
    }
}
