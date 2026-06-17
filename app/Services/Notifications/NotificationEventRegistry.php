<?php

namespace App\Services\Notifications;

use App\Support\ServerSystemdServiceNotificationKeys;

class NotificationEventRegistry
{
    /**
     * @return array{key: string, label: string, category: string|null, severity: string, supports_in_app: bool, supports_email: bool, supports_webhook: bool}
     */
    /** @return array<string, mixed> */
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
                'severity' => $this->severityFor($eventKey),
                'supports_in_app' => true,
                // Import migration events surface action-required moments;
                // default them to email-on per the Q17 cadence (in-app +
                // email at action-required moments only). Uptime down/recovered,
                // degraded, and SSL-expiry are likewise action-required.
                'supports_email' => str_starts_with($eventKey, 'import.migration.')
                    || str_starts_with($eventKey, 'site.uptime.')
                    || $eventKey === 'site.ssl.expiring',
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

    /**
     * Severity bumps to 'warning' for monitoring / alerts / failed-step / cutover-ready
     * / aborted events. Default 'info'.
     */
    protected function severityFor(string $eventKey): string
    {
        if (str_contains($eventKey, 'monitor')
            || str_contains($eventKey, 'uptime')
            || str_contains($eventKey, '.ssl.')
            || str_contains($eventKey, 'alerts')
            || str_ends_with($eventKey, 'step_failed')
            || str_ends_with($eventKey, 'cutover_ready')
            || str_ends_with($eventKey, 'aborted')
            || str_ends_with($eventKey, 'paused_nudge')
            || str_ends_with($eventKey, 'container_launch.failed')
            || str_ends_with($eventKey, 'provision_failed')
            || str_ends_with($eventKey, 'scale_failed')
            || str_ends_with($eventKey, 'security_digest.critical_finding')
            || str_ends_with($eventKey, 'security_digest.warning_finding')
            || str_ends_with($eventKey, 'release_hygiene.critical_finding')
            || str_ends_with($eventKey, 'release_hygiene.warning_finding')
            || str_ends_with($eventKey, 'health.critical_finding')
            || str_ends_with($eventKey, 'health.warning_finding')
            || str_ends_with($eventKey, 'errors.deploy_failed')
            || str_ends_with($eventKey, 'errors.operation_failed')
        ) {
            return 'warning';
        }

        return 'info';
    }
}
