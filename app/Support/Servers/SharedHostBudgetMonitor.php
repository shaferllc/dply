<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Services\Servers\SharedHostNotificationDispatcher;
use Illuminate\Support\Carbon;

/**
 * Evaluates shared-host budget breaches and sends deduplicated notifications.
 */
final class SharedHostBudgetMonitor
{
    public function __construct(
        private SiteLoadAttributor $attributor,
        private SiteLoadAttributionHistory $history,
        private SharedHostBudgetSettings $budgets,
        private SharedHostBudgetEvaluator $evaluator,
        private SharedHostNotificationDispatcher $notifications,
        private HostContentionDetector $contention,
    ) {}

    public function evaluate(Server $server): void
    {
        if (! workspace_shared_host_active($server->organization) || $server->sites()->count() < 2) {
            return;
        }

        if (! ($this->budgets->forServer($server)['alerts_enabled'] ?? true)) {
            return;
        }

        $rollup = $this->history->rollup($server, '24h');
        $rows = $rollup['rows'] ?? [];
        if ($rows === []) {
            $current = $this->attributor->forServer($server, 'current');
            $rows = is_array($current['rows'] ?? null) ? $current['rows'] : [];
        }

        foreach ($this->evaluator->breaches($server, $rows, usePeakShares: true) as $breach) {
            if ($this->shouldNotify($server, (string) $breach['id'], (string) ($breach['observed_pct'] ?? ''))) {
                $this->notifications->notifyBudgetBreach($server, $breach);
                $this->markNotified($server, (string) $breach['id'], (string) ($breach['observed_pct'] ?? ''));
            }
        }

        $attribution = $this->attributor->forServer($server, 'current');
        foreach ($this->contention->events($server, $attribution) as $event) {
            if (($event['severity'] ?? '') !== 'critical') {
                continue;
            }
            $eventId = (string) ($event['id'] ?? '');
            if ($eventId === '') {
                continue;
            }
            if ($this->shouldNotify($server, 'contention-'.$eventId, (string) ($event['severity'] ?? ''))) {
                $this->notifications->notifyContentionEvent($server, $event);
                $this->markNotified($server, 'contention-'.$eventId, (string) ($event['severity'] ?? ''));
            }
        }
    }

    private function shouldNotify(Server $server, string $key, string $fingerprint): bool
    {
        $stateKey = (string) config('server_shared_host.budgets.alert_state_meta_key', 'shared_host_alert_state');
        $state = is_array($server->meta[$stateKey] ?? null) ? $server->meta[$stateKey] : [];
        $previous = is_array($state[$key] ?? null) ? $state[$key] : null;

        if ($previous === null) {
            return true;
        }

        if (($previous['fingerprint'] ?? null) !== $fingerprint) {
            return true;
        }

        $cooldownHours = max(1, (int) config('server_shared_host.budgets.notify_cooldown_hours', 4));
        $notifiedAt = isset($previous['notified_at']) ? Carbon::parse((string) $previous['notified_at']) : null;
        if ($notifiedAt instanceof Carbon && $notifiedAt->lt(now()->subHours($cooldownHours))) {
            return true;
        }

        return false;
    }

    private function markNotified(Server $server, string $key, string $fingerprint): void
    {
        $stateKey = (string) config('server_shared_host.budgets.alert_state_meta_key', 'shared_host_alert_state');
        $meta = $server->meta ?? [];
        $state = is_array($meta[$stateKey] ?? null) ? $meta[$stateKey] : [];
        $state[$key] = [
            'fingerprint' => $fingerprint,
            'notified_at' => now()->toIso8601String(),
        ];
        $meta[$stateKey] = $state;
        $server->update(['meta' => $meta]);
    }
}
