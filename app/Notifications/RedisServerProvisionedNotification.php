<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCredentialShare;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent to the creator when a dedicated redis server finishes provisioning.
 *
 * Carries the host/port plus a reveal-once link to the AUTH password. The
 * password itself is intentionally NOT in the email body — duplicating a secret
 * across mailboxes weakens it. The link expires and is view-limited (see
 * {@see ServerCredentialShare} and ServerCredentialShareController).
 */
class RedisServerProvisionedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Server $server,
        public string $shareToken,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->server->name ?: 'redis server';
        $host = $this->server->ip_address ?: 'your server IP';
        $engine = (string) (data_get($this->server->meta, 'cache_service') ?: 'redis');
        $port = ServerCacheService::defaultPortFor($engine);
        $revealUrl = URL::route('server-credential-shares.show', ['token' => $this->shareToken]);

        return (new MailMessage)
            ->subject(sprintf('[dply] Your redis server is ready — %s', $name))
            ->greeting(sprintf('"%s" is live', $name))
            ->line('Your dedicated redis server finished provisioning and is ready to accept connections.')
            ->line(sprintf('Host: %s', $host))
            ->line(sprintf('Port: %d', $port))
            ->line('For security, the AUTH password is not included in this email. Use the one-time link below to reveal it — it expires in 48 hours and can only be opened a few times:')
            ->action('Reveal credentials', $revealUrl)
            ->line('That page shows the password and a ready-to-paste connection string. Treat the link like a password and don\'t forward it.')
            ->salutation('— dply');
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'event' => 'redis.provisioned',
        ];
    }
}
