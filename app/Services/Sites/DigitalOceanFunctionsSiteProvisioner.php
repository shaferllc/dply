<?php

namespace App\Services\Sites;

use App\Models\Site;

final class DigitalOceanFunctionsSiteProvisioner
{
    /**
     * @return array{ok: bool, hostname: ?string, url: ?string, error: ?string, checked_at: string, checks: list<array<string, mixed>>}
     */
    public function readyResult(Site $site): array
    {
        $site->loadMissing('domains');
        $meta = is_array($site->meta) ? $site->meta : [];
        $config = is_array($meta['digitalocean_functions'] ?? null) ? $meta['digitalocean_functions'] : [];

        $hostname = optional($site->primaryDomain())->hostname;
        $actionUrl = $config['action_url'] ?? null;

        return [
            'ok' => true,
            'hostname' => is_string($hostname) && $hostname !== '' ? $hostname : null,
            'url' => is_string($actionUrl) && $actionUrl !== '' ? $actionUrl : null,
            'error' => null,
            'checked_at' => now()->toIso8601String(),
            'checks' => [],
        ];
    }
}
