<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Notifications\SupervisorProgramsUnhealthyNotification;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class CheckSupervisorHealthCommand extends Command
{
    protected $signature = 'dply:supervisor-check-health';

    protected $description = 'SSH to servers with Supervisor programs, snapshot status, and alert org admins on failure states';

    public function handle(SupervisorProvisioner $provisioner, NotificationPublisher $notificationPublisher): int
    {
        if (! config('dply.supervisor_health_check_enabled', true)) {
            $this->info('Supervisor health checks are disabled (DPLY_SUPERVISOR_HEALTH_CHECK_ENABLED).');

            return self::SUCCESS;
        }

        Server::query()
            ->where('status', Server::STATUS_READY)
            ->whereNotNull('ip_address')
            ->whereHas('supervisorPrograms', fn ($q) => $q->where('is_active', true))
            ->with(['organization', 'supervisorPrograms'])
            ->chunk(50, function ($servers) use ($provisioner, $notificationPublisher): void {
                foreach ($servers as $server) {
                    /** @var Server $server */
                    if (empty($server->ssh_private_key)) {
                        continue;
                    }
                    if ($server->supervisor_package_status === Server::SUPERVISOR_PACKAGE_MISSING) {
                        continue;
                    }
                    try {
                        $out = $provisioner->fetchSupervisorctlStatus($server);
                        $analysis = $provisioner->analyzeStatusForManagedPrograms($server, $out);
                        $drift = false;
                        try {
                            $drift = $provisioner->hasConfigDrift($server);
                        } catch (\Throwable) {
                            // SSH or disk read failure — skip drift for this run
                        }
                        $meta = $server->meta ?? [];
                        $meta['supervisor_health'] = [
                            'checked_at' => now()->toIso8601String(),
                            'ok' => $analysis['ok'] && ! $drift,
                            'summary' => $analysis['summary'],
                            'config_drift' => $drift,
                            'detail' => Str::limit($out, 8000),
                        ];
                        $server->update(['meta' => $meta]);

                        $issueSummary = $analysis['summary'];
                        if ($drift) {
                            $issueSummary .= ' '.__('Supervisor config files on the server differ from Dply (drift). Run sync or inspect the Sync preview tab.');
                        }

                        if ((! $analysis['ok'] || $drift) && $server->organization) {
                            $cacheKey = 'supervisor-health-notify:'.$server->id;
                            if (Cache::add($cacheKey, true, now()->addHours(6))) {
                                $users = $server->organization->users()
                                    ->wherePivotIn('role', ['owner', 'admin'])
                                    ->get();
                                if ($users->isNotEmpty()) {
                                    $event = $notificationPublisher->publish(
                                        eventKey: 'server.supervisor.unhealthy',
                                        subject: $server,
                                        title: '['.config('app.name').'] Supervisor programs need attention',
                                        body: $issueSummary,
                                        url: route('servers.daemons', $server, absolute: true),
                                        recipientUsers: $users->pluck('id')->all(),
                                        metadata: [
                                            'server_id' => $server->id,
                                            'server_name' => $server->name,
                                            'organization_name' => $server->organization->name,
                                            'summary' => $issueSummary,
                                            'config_drift' => $drift,
                                        ],
                                    );

                                    Notification::send($users, new SupervisorProgramsUnhealthyNotification($event));
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->warn($server->name.': '.$e->getMessage());
                    }
                }
            });

        return self::SUCCESS;
    }
}
