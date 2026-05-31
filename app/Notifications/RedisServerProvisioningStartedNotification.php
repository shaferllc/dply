<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent to the creator the moment a dedicated redis server begins provisioning.
 * A heads-up only — the "it's ready" email with connection details follows when
 * provisioning completes (see {@see RedisServerProvisionedNotification}).
 */
class RedisServerProvisioningStartedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Server $server,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->server->name ?: 'redis server';
        $region = $this->server->region ?: '—';
        $size = $this->server->size ?: '—';
        $journeyUrl = URL::route('servers.journey', $this->server);

        return (new MailMessage)
            ->subject(sprintf('[dply] Provisioning started — %s', $name))
            ->greeting(sprintf('Spinning up "%s"', $name))
            ->line('We\'ve started provisioning your dedicated redis server.')
            ->line(sprintf('Region: %s', $region))
            ->line(sprintf('Size: %s', $size))
            ->line('This usually takes a minute or two. We\'ll email the connection details the moment it\'s ready.')
            ->action('Watch progress', $journeyUrl)
            ->salutation('— dply');
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'event' => 'redis.provisioning_started',
        ];
    }
}
