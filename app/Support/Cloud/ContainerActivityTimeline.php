<?php

declare(strict_types=1);

namespace App\Support\Cloud;

use App\Models\Site;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Reads everything dply has captured under site.meta.container.*
 * and turns it into a timeline of dated events. Used by the
 * container dashboard "Recent activity" panel.
 *
 * No new database surface — the source-of-truth is the meta
 * column itself, which the provision/redeploy/poll/domain jobs
 * already keep up to date. This class only normalizes those
 * scattered keys into a single chronological list.
 */
class ContainerActivityTimeline
{
    /**
     * @return list<array{at: ?CarbonInterface, kind: string, label: string, detail: ?string}>
     */
    public static function for(Site $site): array
    {
        $meta = ($site->meta );
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];

        $events = [];

        if (! empty($container['provisioned_at'])) {
            $events[] = [
                'at' => self::parse($container['provisioned_at']),
                'kind' => 'provisioned',
                'label' => 'Provisioned on backend',
                'detail' => $container['backend'] ?? null,
            ];
        }

        if (! empty($container['last_deploy_started_at'])) {
            $events[] = [
                'at' => self::parse($container['last_deploy_started_at']),
                'kind' => 'deploy',
                'label' => 'Redeploy started',
                'detail' => isset($container['last_deployment_id']) && is_string($container['last_deployment_id'])
                    ? 'deployment '.$container['last_deployment_id']
                    : null,
            ];
        }

        if (! empty($container['last_error_at'])) {
            $events[] = [
                'at' => self::parse($container['last_error_at']),
                'kind' => 'error',
                'label' => 'Backend reported error',
                'detail' => is_string($container['last_error'] ?? null) ? $container['last_error'] : null,
            ];
        }

        if (! empty($container['last_poll_at'])) {
            $phase = is_string($container['last_phase'] ?? null) ? $container['last_phase'] : 'unknown';
            $pollError = is_string($container['last_poll_error'] ?? null) ? $container['last_poll_error'] : null;
            $events[] = [
                'at' => self::parse($container['last_poll_at']),
                'kind' => $pollError !== null ? 'poll_error' : 'poll',
                'label' => $pollError !== null ? 'Status poll failed' : 'Status polled',
                'detail' => $pollError !== null ? $pollError : 'phase: '.$phase,
            ];
        }

        if (! empty($container['torn_down_at'])) {
            $events[] = [
                'at' => self::parse($container['torn_down_at']),
                'kind' => 'teardown',
                'label' => 'Tear-down completed',
                'detail' => null,
            ];
        }

        if (is_array($container['domains'] ?? null)) {
            foreach ($container['domains'] as $hostname => $info) {
                if (! is_array($info)) {
                    continue;
                }
                $events[] = [
                    'at' => self::parse($info['attached_at'] ?? null),
                    'kind' => 'domain_attached',
                    'label' => 'Domain attached',
                    'detail' => (string) $hostname,
                ];
            }
        }

        // Newest first — operator's eye lands on the most recent
        // event, scrolls down to find context.
        usort($events, function (array $a, array $b): int {
            $aT = $a['at']?->getTimestamp() ?? 0;
            $bT = $b['at']?->getTimestamp() ?? 0;

            return $bT <=> $aT;
        });

        return $events;
    }

    private static function parse(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
