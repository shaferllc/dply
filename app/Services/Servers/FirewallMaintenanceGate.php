<?php

namespace App\Services\Servers;

use App\Models\Server;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class FirewallMaintenanceGate
{
    public function blockedReason(Server $server): ?string
    {
        $meta = $server->meta ?? [];
        $until = $meta['firewall_maintenance_until'] ?? null;
        if (! is_string($until) || $until === '') {
            return null;
        }
        try {
            $end = Carbon::parse($until);
        } catch (\Throwable) {
            return null;
        }
        if ($end->isFuture()) {
            return __('Firewall changes are frozen until :time (maintenance window).', [
                'time' => $end->timezone(config('app.timezone'))->toIso8601String(),
            ]);
        }

        return null;
    }

    public function setUntil(Server $server, ?CarbonInterface $until): void
    {
        $meta = $server->meta ?? [];
        if ($until === null) {
            unset($meta['firewall_maintenance_until']);
        } else {
            $meta['firewall_maintenance_until'] = $until->toIso8601String();
        }
        $server->update(['meta' => $meta]);
        $server->refresh();
    }
}
