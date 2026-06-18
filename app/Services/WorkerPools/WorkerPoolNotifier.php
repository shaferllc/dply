<?php

namespace App\Services\WorkerPools;

use App\Models\Server;
use App\Models\User;
use App\Models\WorkerPool;
use App\Notifications\WorkerPoolScaleNotification;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Notifications\Services\ResourceNotificationContextResolver;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Publishes worker-pool scaling events (in-app inbox + webhooks via the
 * NotificationPublisher) and sends the matching email. The pool's primary
 * server is the notification subject, so recipients resolve to the server's
 * stakeholders (creator + org owners/admins) and the link points at the
 * pool's workspace page.
 */
class WorkerPoolNotifier
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly ResourceNotificationContextResolver $contextResolver,
    ) {}

    public function scaleStarted(WorkerPool $pool, int $from, int $to): void
    {
        $verb = $to > $from ? 'up' : 'down';
        $this->emit(
            $pool,
            'worker_pool.scale_started',
            sprintf('[%s] %s scaling %s (%d → %d)', config('app.name'), $pool->name, $verb, $from, $to),
            sprintf('Scaling %s from %d to %d worker(s). Provisioning runs in the background.', $verb, $from, $to),
            ['from_count' => $from, 'desired_count' => $to],
            emailGatedByOrgPref: true,
        );
    }

    public function scaled(WorkerPool $pool, int $active): void
    {
        $this->emit(
            $pool,
            'worker_pool.scaled',
            sprintf('[%s] %s scaled — %d worker(s) active', config('app.name'), $pool->name, $active),
            sprintf('The pool settled at %d active worker(s).', $active),
            ['desired_count' => $pool->desired_count, 'active_count' => $active],
            emailGatedByOrgPref: true,
        );
    }

    public function scaleFailed(WorkerPool $pool, string $error): void
    {
        $this->emit(
            $pool,
            'worker_pool.scale_failed',
            sprintf('[%s] %s scaling failed', config('app.name'), $pool->name),
            'A member failed to provision or deploy while scaling — the pool may be degraded.',
            [
                'desired_count' => $pool->desired_count,
                'active_count' => $pool->activeMemberCount(),
                'error' => mb_substr($error, 0, 1000),
            ],
            // Failures are action-required — always email, regardless of the
            // org's routine-email preference (mirrors provision-failure).
            emailGatedByOrgPref: false,
        );
    }

    /**
     * @param  array<string, mixed> $metadata
     */
    private function emit(
        WorkerPool $pool,
        string $eventKey,
        string $title,
        string $body,
        array $metadata,
        bool $emailGatedByOrgPref,
    ): void {
        if (! config('dply.worker_pool_notifications', true)) {
            return;
        }

        $server = $pool->primaryServer ?? $pool->sourceServer;
        if (! $server instanceof Server) {
            return;
        }

        $url = route('servers.worker-pool', $server, absolute: true);
        $metadata = array_merge(['pool_id' => (string) $pool->id, 'pool_name' => $pool->name], $metadata);

        // In-app inbox + broadcast + webhook subscriptions (the publisher does
        // NOT send email — that's done explicitly below).
        $event = $this->publisher->publish(
            eventKey: $eventKey,
            subject: $server,
            title: $title,
            body: $body,
            url: $url,
            metadata: $metadata,
        );

        $org = $server->organization;
        if ($emailGatedByOrgPref && $org && ! $org->wantsDeployEmailNotifications()) {
            return;
        }

        $recipientIds = $this->contextResolver->resolve($server)['stakeholder_user_ids'];
        $recipients = User::query()->whereIn('id', $recipientIds)->get();
        if ($recipients->isNotEmpty()) {
            NotificationFacade::send($recipients, new WorkerPoolScaleNotification($event));
        }
    }
}
