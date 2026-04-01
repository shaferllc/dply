<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Notifications\SshKeyRotationDueNotification;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProcessSshKeyRotationRemindersCommand extends Command
{
    protected $signature = 'dply:ssh-key-rotation-reminders';

    protected $description = 'Notify server owners when stored SSH keys pass their review-after date';

    public function handle(NotificationPublisher $notificationPublisher): int
    {
        $today = now()->toDateString();

        ServerAuthorizedKey::query()
            ->whereNotNull('review_after')
            ->whereDate('review_after', '<=', $today)
            ->whereHas('server', fn ($q) => $q->where('status', Server::STATUS_READY))
            ->with(['server.user'])
            ->chunkById(100, function ($keys) use ($today, $notificationPublisher): void {
                foreach ($keys as $key) {
                    $user = $key->server?->user;
                    if (! $user) {
                        continue;
                    }

                    $cacheKey = 'ssh_key_rotation_reminder:'.$key->id.':'.$today;
                    if (Cache::has($cacheKey)) {
                        continue;
                    }

                    $event = $notificationPublisher->publish(
                        eventKey: 'server.ssh_key_rotation_due',
                        subject: $key,
                        title: 'SSH key review due',
                        body: 'The key “'.$key->name.'” on '.$key->server->name.' is due for review.',
                        url: route('servers.ssh-keys', $key->server, absolute: true),
                        recipientUsers: [$user],
                        metadata: [
                            'authorized_key_id' => $key->id,
                            'authorized_key_name' => $key->name,
                            'server_id' => $key->server->id,
                            'server_name' => $key->server->name,
                        ],
                    );
                    $user->notify(new SshKeyRotationDueNotification($event));
                    Cache::put($cacheKey, true, now()->addHours(36));
                }
            });

        $this->info('SSH key rotation reminders processed.');

        return self::SUCCESS;
    }
}
