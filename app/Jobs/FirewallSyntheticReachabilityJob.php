<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerFirewallAuditEvent;
use App\Services\Servers\ServerFirewallAuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Optional HTTP(S) GET to organization-configured URL after firewall apply (smoke test).
 */
class FirewallSyntheticReachabilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $serverId,
    ) {}

    public function handle(ServerFirewallAuditLogger $audit): void
    {
        $server = Server::query()->find($this->serverId);
        if (! $server) {
            return;
        }
        $org = $server->organization;
        if (! $org) {
            return;
        }
        $url = $org->mergedFirewallSettings()['synthetic_probe_url'] ?? null;
        if (! is_string($url) || $url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        try {
            $response = Http::timeout(8)->get($url);
            $ok = $response->successful();
            $audit->record($server, ServerFirewallAuditEvent::EVENT_SYNTHETIC_PROBE, [
                'url' => $url,
                'ok' => $ok,
                'status' => $response->status(),
            ], null);
        } catch (\Throwable $e) {
            $audit->record($server, ServerFirewallAuditEvent::EVENT_SYNTHETIC_PROBE, [
                'url' => $url,
                'ok' => false,
                'error' => $e->getMessage(),
            ], null);
        }
    }
}
