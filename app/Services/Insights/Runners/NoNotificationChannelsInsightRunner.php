<?php

namespace App\Services\Insights\Runners;

use App\Models\InsightFinding;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;

/**
 * Detect when a server's organization has no notification channels wired up,
 * or has channels but no subscription that would route this server's alerts.
 *
 * Insights, deploy notifications, SSH-login alerts — all of these fan out
 * through NotificationChannel rows. Without at least one channel and one
 * subscription, the org will accumulate findings that nobody sees.
 *
 * No SSH probe. Pure DB check on org channels + this server's subscriptions.
 */
class NoNotificationChannelsInsightRunner implements InsightRunnerInterface
{
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }

        $server->loadMissing('organization');
        $org = $server->organization;
        if ($org === null) {
            return [];
        }

        // Subscriptions targeting *this* server are the strongest signal that
        // alerts are wired up. If one exists, by definition the channel it
        // points at is reachable — no need to introspect ownership.
        $serverSubscriptions = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $server->id)
            ->count();
        if ($serverSubscriptions > 0) {
            return [];
        }

        // No subscriptions for this server. Count channels that *could* route
        // alerts on this org's behalf — owned by the org, by any of its
        // teams, or by any of its user members. The user-owned bucket matters:
        // operators commonly own personal Slack channels that they then
        // subscribe to servers.
        $userMemberIds = $org->users()->pluck('users.id');
        $teamIds = $org->teams()->pluck('id');

        $orgWideChannels = NotificationChannel::query()
            ->where(function ($q) use ($org, $teamIds, $userMemberIds): void {
                $q->where(function ($q) use ($org): void {
                    $q->where('owner_type', $org->getMorphClass())
                        ->where('owner_id', $org->id);
                })
                    ->orWhere(function ($q) use ($teamIds): void {
                        $q->where('owner_type', Team::class)
                            ->whereIn('owner_id', $teamIds);
                    })
                    ->orWhere(function ($q) use ($userMemberIds): void {
                        $q->where('owner_type', User::class)
                            ->whereIn('owner_id', $userMemberIds);
                    });
            })
            ->count();

        if ($orgWideChannels === 0) {
            return [
                new InsightCandidate(
                    insightKey: 'no_notification_channels',
                    dedupeHash: 'org-no-channels',
                    severity: InsightFinding::SEVERITY_WARNING,
                    title: __('No notification channels configured'),
                    body: __('Insights, deploy failures, and SSH login alerts have nowhere to go. Add a channel on the Insights → Notifications tab (Slack, email, webhook).'),
                    meta: [
                        'signal' => [
                            'org_wide_channel_count' => 0,
                            'server_subscription_count' => 0,
                        ],
                    ],
                ),
            ];
        }

        // Channels exist somewhere reachable, just none subscribed to this
        // server. Suggestion-class — operators may intentionally route alerts
        // for some servers elsewhere.
        return [
            new InsightCandidate(
                insightKey: 'no_notification_channels',
                dedupeHash: 'server-no-subscriptions',
                severity: InsightFinding::SEVERITY_INFO,
                title: __('No alert routing for this server'),
                body: __('Your organization has notification channels but none are subscribed to this server\'s events. Wire one up on the Insights → Notifications tab.'),
                meta: [
                    'signal' => [
                        'org_wide_channel_count' => $orgWideChannels,
                        'server_subscription_count' => 0,
                    ],
                ],
                kind: InsightFinding::KIND_SUGGESTION,
            ),
        ];
    }
}
