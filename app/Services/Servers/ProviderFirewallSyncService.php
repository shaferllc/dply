<?php

namespace App\Services\Servers;

use App\Models\Server;

/**
 * Placeholder for mirroring UFW rules to cloud provider security groups (DO, Hetzner, AWS, …).
 * Source of truth remains Dply + on-host UFW until this is implemented per provider.
 */
class ProviderFirewallSyncService
{
    public function describe(Server $server): string
    {
        if (! config('server_firewall.provider_sync.enabled', false)) {
            return __('Provider firewall sync is off. UFW on the server is the active enforcement layer.');
        }

        return __('Provider API sync is not configured for this server’s provider yet. UFW rules in Dply still apply over SSH only.');
    }
}
