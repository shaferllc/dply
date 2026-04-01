<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Models\ServerSystemdNotificationDigestLine;
use App\Support\ServerSystemdServiceNotificationKeys;
use Illuminate\Support\Str;

final class ServerSystemdNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * @param  array{kind: string, unit: string, label: string, detail?: string|null}  $event
     */
    public function notifyIfSubscribed(Server $server, array $event): void
    {
        $kind = (string) ($event['kind'] ?? '');
        $unit = (string) ($event['unit'] ?? '');
        if ($unit === '' || ! in_array($kind, ServerSystemdServiceNotificationKeys::KINDS, true)) {
            return;
        }

        try {
            $eventKey = ServerSystemdServiceNotificationKeys::eventKey($unit, $kind);
        } catch (\InvalidArgumentException) {
            return;
        }

        $subs = NotificationSubscription::query()
            ->where('event_key', $eventKey)
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $server->id)
            ->with('channel')
            ->get();

        if ($subs->isEmpty()) {
            return;
        }

        $label = (string) ($event['label'] ?? $unit);
        $detail = isset($event['detail']) && is_string($event['detail']) ? $event['detail'] : null;
        $subject = '['.config('app.name').'] '.$server->name.' — '.$label.' — '.$kind;
        $lines = [
            __('Server: :name', ['name' => $server->name]),
            __('Unit: :unit', ['unit' => $unit]),
            __('Change: :kind', ['kind' => $kind]),
        ];
        if ($detail !== null && $detail !== '') {
            $lines[] = $detail;
        }
        $text = implode("\n", $lines);
        $url = self::servicesWorkspaceUrl($server, $unit, 'alerts', $detail);

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $server,
            title: $subject,
            body: $text,
            url: $url,
            metadata: [
                'server_id' => $server->id,
                'unit' => $unit,
                'kind' => $kind,
                'label' => $label,
                'detail' => $detail,
            ],
        );

        $org = $server->organization;
        $digest = $org !== null
            && ($org->mergedServicesPreferences()['systemd_notifications_digest'] ?? 'immediate') === 'hourly';

        if ($digest && $org !== null) {
            $bucket = now('UTC')->format('Y-m-d-H');
            $digestLine = '• '.$label.' ('.$unit.'): '.$kind.($detail !== null && $detail !== '' ? ' — '.$detail : '');

            foreach ($subs as $sub) {
                ServerSystemdNotificationDigestLine::query()->create([
                    'notification_channel_id' => $sub->notification_channel_id,
                    'server_id' => $server->id,
                    'organization_id' => $org->id,
                    'digest_bucket' => $bucket,
                    'unit' => $unit,
                    'event_kind' => $kind,
                    'line' => $digestLine,
                ]);
            }

            return;
        }

        foreach ($subs as $sub) {
            $channel = $sub->channel;
            if (! $channel instanceof NotificationChannel) {
                continue;
            }
            $channel->sendOperationalMessage($subject, $text, $url, __('Open Services'));
        }
    }

    public static function servicesWorkspaceUrl(Server $server, string $unit, string $modal, ?string $snippet = null): string
    {
        $q = [
            'systemd_unit' => $unit,
            'systemd_modal' => $modal,
        ];
        if ($snippet !== null && $snippet !== '') {
            $q['systemd_snippet'] = Str::limit(preg_replace('/\s+/', ' ', $snippet) ?? '', 240, '…');
        }

        return route('servers.services', array_merge(['server' => $server], $q), absolute: true);
    }
}
