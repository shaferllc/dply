<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\WorkerPool;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Alerts when a worker pool's primary (scheduler owner) has been unhealthy for a
 * sustained window. Per the design decision, failover is MANUAL — this only
 * notifies operators (who promote a replica from the pool UI). Auto-promotion is
 * intentionally not done (split-brain risk).
 */
class WorkerPoolPrimaryHealthCommand extends Command
{
    protected $signature = 'dply:worker-pools:primary-health';

    protected $description = 'Alert when a worker pool primary is unhealthy (manual promote required).';

    /** Minutes a primary must stay unhealthy before alerting. */
    private const UNHEALTHY_MINUTES = 10;

    /** Re-alert cooldown to avoid spamming. */
    private const ALERT_COOLDOWN_MINUTES = 60;

    public function handle(NotificationPublisher $publisher): int
    {
        $alerted = 0;

        WorkerPool::query()->with('primaryServer')->each(function (WorkerPool $pool) use ($publisher, &$alerted): void {
            $primary = $pool->primaryServer;
            if (! $primary instanceof Server) {
                return;
            }

            $unhealthy = $primary->health_status === Server::HEALTH_UNREACHABLE
                && $primary->last_health_check_at !== null
                && $primary->last_health_check_at->lt(now()->subMinutes(self::UNHEALTHY_MINUTES));

            if (! $unhealthy) {
                return;
            }

            // Cooldown.
            $lastAlert = $pool->meta['primary_alert']['last_at'] ?? null;
            if (is_string($lastAlert) && Carbon::parse($lastAlert)->diffInMinutes(now(), absolute: true) < self::ALERT_COOLDOWN_MINUTES) {
                return;
            }

            $org = $pool->organization;
            $recipients = $org ? $org->users()->wherePivotIn('role', ['owner', 'admin'])->get()->all() : null;

            $publisher->publish(
                eventKey: 'server.insights_alerts',
                subject: $primary,
                title: '['.config('app.name').'] Worker pool primary unhealthy — promote a replica',
                body: sprintf(
                    'The primary "%s" for worker pool "%s" has been unreachable for over %d minutes. Promote a healthy replica from the pool page to restore the scheduler.',
                    $primary->name,
                    $pool->name,
                    self::UNHEALTHY_MINUTES,
                ),
                url: null,
                metadata: [
                    'worker_pool_id' => (string) $pool->id,
                    'primary_server_id' => (string) $primary->id,
                    'reason' => 'worker_pool_primary_unhealthy',
                ],
                contextOverrides: array_filter([
                    'organization_id' => $org?->id,
                    'team_id' => $primary->team_id,
                    'resource_type' => Server::class,
                    'resource_id' => (string) $primary->getKey(),
                ]),
                recipientUsers: $recipients,
            );

            $meta = $pool->meta;
            $meta['primary_alert'] = ['last_at' => now()->toIso8601String()];
            $pool->forceFill(['meta' => $meta])->save();
            $alerted++;
        });

        $this->components->info("Sent {$alerted} worker-pool primary health alert(s).");

        return self::SUCCESS;
    }
}
