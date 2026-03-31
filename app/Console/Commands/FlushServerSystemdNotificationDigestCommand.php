<?php

namespace App\Console\Commands;

use App\Models\NotificationChannel;
use App\Models\Server;
use App\Models\ServerSystemdNotificationDigestLine;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class FlushServerSystemdNotificationDigestCommand extends Command
{
    protected $signature = 'systemd:flush-notification-digest';

    protected $description = 'Send batched systemd service alerts for the previous UTC hour bucket';

    public function handle(): int
    {
        $bucket = now('UTC')->subHour()->format('Y-m-d-H');
        $ids = ServerSystemdNotificationDigestLine::query()
            ->where('digest_bucket', $bucket)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return self::SUCCESS;
        }

        $rows = ServerSystemdNotificationDigestLine::query()->whereIn('id', $ids)->get();
        foreach ($rows->groupBy('notification_channel_id') as $channelId => $group) {
            $channel = NotificationChannel::query()->find($channelId);
            if ($channel === null) {
                ServerSystemdNotificationDigestLine::query()->whereIn('id', $group->pluck('id'))->delete();

                continue;
            }

            /** @var Collection<int, ServerSystemdNotificationDigestLine> $group */
            $first = $group->first();
            $server = $first !== null ? Server::query()->find($first->server_id) : null;
            $lines = $group->pluck('line')->unique()->values()->implode("\n");
            $subject = '['.config('app.name').'] '.($server?->name ?? __('Server')).' — '.__(
                'Systemd alerts (:count)',
                ['count' => $group->count()]
            );
            $url = $server !== null
                ? route('servers.services', ['server' => $server, 'systemd_modal' => 'alerts'], absolute: true)
                : url('/');

            $channel->sendOperationalMessage($subject, $lines, $url, __('Open Services'));
            ServerSystemdNotificationDigestLine::query()->whereIn('id', $group->pluck('id'))->delete();
        }

        return self::SUCCESS;
    }
}
