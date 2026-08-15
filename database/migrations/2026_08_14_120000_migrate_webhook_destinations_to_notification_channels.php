<?php

declare(strict_types=1);

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\NotificationWebhookDestination;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Database\Migrations\Migration;

/**
 * Fold notification_webhook_destinations into the notification-channel system.
 *
 * The two were parallel fan-outs off the same NotificationEvent — see the old
 * NotificationRoutingResolver, which called sendOperationalMessage() and then
 * NotificationWebhookDestinationRouter::route() one after the other. Destinations
 * were the weaker of the pair: 3 drivers vs 10, 9 hard-coded events vs the full
 * config/notification_events.php catalogue, pasted URLs only (no OAuth connect),
 * and no test-send. Its Teams driver also still posted a MessageCard to an Office
 * 365 connector, which Microsoft retired in May 2026 — so that path was already
 * dead on arrival.
 *
 * The one thing destinations could do that subscriptions could not was scope to
 * "all sites in this org". That gap is closed first (subscriptions may now hang
 * off an Organization), which is what makes this migration possible at all.
 *
 * Source rows are deliberately LEFT INTACT. The table and model stay behind as
 * deprecated so this is recoverable by hand if a mapping turns out wrong; a later
 * change can drop them once the dust settles.
 */
return new class extends Migration
{
    /**
     * hook event => dply event key.
     *
     * Mirrors NotificationWebhookDestinationRouter::mapEvent() in reverse. Several
     * hook events collapse onto one key (the router discriminated on metadata
     * afterwards), so the resulting subscription set is deduped — subscribing to
     * `site.deployments` covers success/failed/skipped together.
     */
    private const EVENT_MAP = [
        'deploy_success' => 'site.deployments',
        'deploy_failed' => 'site.deployments',
        'deploy_skipped' => 'site.deployments',
        'deploy_started' => 'site.deployment_started',
        'uptime_down' => 'site.uptime.down',
        'uptime_recovered' => 'site.uptime.down',
        'uptime_degraded' => 'site.uptime.degraded',
        'ssl_expiring' => 'site.ssl.expiring',
        'insight_opened' => 'server.insights_alerts',
        'insight_resolved' => 'server.insights_alerts',
    ];

    /**
     * Insights are server-scoped events, and the old UI said as much ("org-wide
     * only"). A site-scoped subscription would never match them, so these always
     * migrate to the organization regardless of the destination's own scope.
     */
    private const ORG_SCOPED_KEYS = ['server.insights_alerts'];

    private const DRIVER_MAP = [
        NotificationWebhookDestination::DRIVER_SLACK => NotificationChannel::TYPE_SLACK,
        NotificationWebhookDestination::DRIVER_DISCORD => NotificationChannel::TYPE_DISCORD,
        NotificationWebhookDestination::DRIVER_TEAMS => NotificationChannel::TYPE_MICROSOFT_TEAMS,
    ];

    public function up(): void
    {
        $migrated = 0;
        $skipped = 0;

        NotificationWebhookDestination::query()->cursor()->each(
            function (NotificationWebhookDestination $destination) use (&$migrated, &$skipped): void {
                $organization = Organization::query()->find($destination->organization_id);
                $type = self::DRIVER_MAP[$destination->driver] ?? null;
                $url = $destination->webhook_url;

                if (! $organization instanceof Organization || $type === null || ! is_string($url) || $url === '') {
                    $skipped++;

                    return;
                }

                // A disabled destination was delivering nothing, so it earns a
                // channel (the credential is worth keeping) but no subscriptions —
                // re-enabling means ticking the events in the matrix.
                $channel = $this->channelFor($organization, $type, $url, (string) $destination->name);

                if ($destination->enabled) {
                    $this->subscribe($channel, $destination, $organization);
                }

                $migrated++;
            }
        );

        if ($migrated > 0 || $skipped > 0) {
            echo "  migrated {$migrated} webhook destination(s) to notification channels";
            echo $skipped > 0 ? ", skipped {$skipped} unmappable\n" : "\n";
        }
    }

    /**
     * Reuse an existing channel with the same type + URL rather than minting a
     * duplicate: an org that already had, say, a Slack channel pointed at the same
     * incoming webhook would otherwise start getting two copies of every alert —
     * the exact problem this migration exists to remove.
     */
    private function channelFor(Organization $organization, string $type, string $url, string $name): NotificationChannel
    {
        $existing = $organization->notificationChannels()
            ->where('type', $type)
            ->get()
            ->first(fn (NotificationChannel $c): bool => ($c->config['webhook_url'] ?? null) === $url);

        if ($existing instanceof NotificationChannel) {
            return $existing;
        }

        return $organization->notificationChannels()->create([
            'type' => $type,
            'label' => $name !== '' ? $name : __('Imported webhook'),
            'config' => ['webhook_url' => $url],
        ]);
    }

    private function subscribe(NotificationChannel $channel, NotificationWebhookDestination $destination, Organization $organization): void
    {
        $events = is_array($destination->events) ? $destination->events : [];

        foreach ($events as $hookEvent) {
            $key = self::EVENT_MAP[$hookEvent] ?? null;
            if ($key === null) {
                continue;
            }

            [$subscribableType, $subscribableId] = $this->scopeFor($key, $destination, $organization);
            if ($subscribableId === null) {
                continue;
            }

            // firstOrCreate, not create: several hook events collapse onto one key.
            NotificationSubscription::query()->firstOrCreate([
                'notification_channel_id' => $channel->id,
                'subscribable_type' => $subscribableType,
                'subscribable_id' => $subscribableId,
                'event_key' => $key,
            ]);
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function scopeFor(string $key, NotificationWebhookDestination $destination, Organization $organization): array
    {
        if (in_array($key, self::ORG_SCOPED_KEYS, true) || $destination->site_id === null) {
            return [Organization::class, (string) $organization->id];
        }

        return [Site::class, (string) $destination->site_id];
    }

    public function down(): void
    {
        // Intentionally not reversible. Nothing was deleted — the
        // notification_webhook_destinations rows are still there — so rolling
        // back would only need the created channels/subscriptions removed, and
        // guessing which of those were ours risks deleting an operator's own
        // work. Remove them by hand if you truly need to.
    }
};
