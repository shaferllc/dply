<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Shared load/save for a per-channel notification matrix (channel → its events),
 * used by the central site and server Notifications pages. Each channel routes its
 * own set of events, so different events can go to different channels in one place.
 *
 * Two guarantees that keep it in sync with the per-feature Notifications tabs (which
 * edit the same {@see NotificationSubscription} rows):
 *  - Only the channels passed in (the ones the user can see/assign) are ever read or
 *    written — subscriptions on other channels are never silently wiped.
 *  - Per shown channel it reconciles to the desired set: ticking adds, unticking
 *    removes, unchanged is left alone (no blanket delete-and-recreate).
 *
 * "managed keys" bounds the matrix to a known event set (e.g. all server.* keys, or
 * the site's keys). Anything outside it — e.g. dynamic systemd per-unit keys — is
 * out of scope and untouched.
 */
final class NotificationSubscriptionMatrix
{
    /**
     * Build channelId => list<eventKey> for the subject, limited to managed keys
     * and the assignable channels.
     *
     * @param  list<string>  $managedKeys
     * @param  Collection<int, NotificationChannel>  $assignableChannels
     * @return array<string, list<string>>
     */
    public static function load(string $subjectType, string $subjectId, array $managedKeys, Collection $assignableChannels): array
    {
        $map = [];
        foreach ($assignableChannels as $channel) {
            $map[(string) $channel->id] = [];
        }

        if ($managedKeys === [] || $map === []) {
            return $map;
        }

        $subs = NotificationSubscription::query()
            ->where('subscribable_type', $subjectType)
            ->where('subscribable_id', $subjectId)
            ->whereIn('event_key', $managedKeys)
            ->get(['notification_channel_id', 'event_key']);

        foreach ($subs as $sub) {
            $cid = (string) $sub->notification_channel_id;
            if (array_key_exists($cid, $map)) {
                $map[$cid][] = (string) $sub->event_key;
            }
        }

        return $map;
    }

    /**
     * Reconcile subscriptions to the desired selections — only for the channels
     * present in $selections (a channel absent from the payload is never touched, so
     * a partial submit can't wipe channels it didn't include). Each channel must be
     * in $assignableChannels; unknown channels are skipped.
     *
     * @param  list<string>  $managedKeys
     * @param  Collection<int, NotificationChannel>  $assignableChannels
     * @param  array<string, mixed>  $selections  channelId => list<eventKey>
     * @return array{changed: int, added: int, removed: int}
     */
    public static function save(string $subjectType, string $subjectId, array $managedKeys, Collection $assignableChannels, array $selections): array
    {
        $changed = 0;
        $added = 0;
        $removed = 0;

        $assignableById = $assignableChannels->keyBy(fn ($channel) => (string) $channel->id);

        foreach ($selections as $cid => $keys) {
            $cid = (string) $cid;
            $channel = $assignableById->get($cid);
            if ($channel === null) {
                continue;
            }

            $desired = array_values(array_unique(array_intersect(
                $managedKeys,
                array_map('strval', (array) $keys),
            )));

            $existing = NotificationSubscription::query()
                ->where('notification_channel_id', $channel->id)
                ->where('subscribable_type', $subjectType)
                ->where('subscribable_id', $subjectId)
                ->whereIn('event_key', $managedKeys)
                ->get();
            $existingKeys = $existing->pluck('event_key')->map(fn ($k) => (string) $k)->all();

            $toAdd = array_values(array_diff($desired, $existingKeys));
            $toRemove = array_values(array_diff($existingKeys, $desired));
            if ($toAdd === [] && $toRemove === []) {
                continue;
            }

            Gate::authorize('manageNotificationChannels', $channel->owner);

            foreach ($toRemove as $key) {
                $existing->firstWhere('event_key', $key)?->delete();
                $removed++;
            }
            foreach ($toAdd as $key) {
                NotificationSubscription::query()->create([
                    'notification_channel_id' => $channel->id,
                    'subscribable_type' => $subjectType,
                    'subscribable_id' => $subjectId,
                    'event_key' => $key,
                ]);
                $added++;
            }
            $changed++;
        }

        return ['changed' => $changed, 'added' => $added, 'removed' => $removed];
    }
}
