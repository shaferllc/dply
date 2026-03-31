<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerFirewallAuditEvent;
use App\Models\User;
use App\Services\Servers\FirewallMaintenanceGate;
use App\Services\Servers\ServerFirewallApplyRecorder;
use App\Services\Servers\ServerFirewallAuditLogger;
use App\Services\Servers\ServerFirewallProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ApplyServerFirewallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $serverId,
        public ?string $userId = null,
    ) {}

    public function handle(
        ServerFirewallProvisioner $firewall,
        ServerFirewallAuditLogger $audit,
        FirewallMaintenanceGate $maintenance,
        ServerFirewallApplyRecorder $recorder,
    ): void {
        $server = Server::query()->find($this->serverId);
        if (! $server) {
            return;
        }

        $user = $this->userId ? User::query()->find($this->userId) : null;

        if ($reason = $maintenance->blockedReason($server)) {
            $audit->record($server, ServerFirewallAuditEvent::EVENT_SCHEDULED_APPLY, [
                'skipped' => $reason,
            ], $user);

            return;
        }

        try {
            $out = $firewall->apply($server);
            $audit->record($server, ServerFirewallAuditEvent::EVENT_SCHEDULED_APPLY, [
                'output_excerpt' => Str::limit(trim($out), 2000),
            ], $user);
            $recorder->recordSuccess($server, $user, null, $out, 'scheduled');
        } catch (\Throwable $e) {
            $recorder->recordFailure($server, $user, null, $e->getMessage(), 'scheduled');
            $audit->record($server, ServerFirewallAuditEvent::EVENT_SCHEDULED_APPLY, [
                'error' => $e->getMessage(),
            ], $user);
            throw $e;
        }
    }
}
