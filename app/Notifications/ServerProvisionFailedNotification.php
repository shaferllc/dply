<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Sent to the server's creator when provisioning fails (after auto-retry is
 * exhausted). Unlike {@see ServerProvisionedCredentialsNotification} this is
 * NOT gated by an org toggle — silent failures are worse than a few extra
 * emails. Operators need to know within seconds, not when they happen to
 * glance at the fleet list and notice a server still spinning.
 *
 * Includes a short excerpt of the last error so the recipient can decide
 * whether to retry or open the journey for the full transcript. The
 * journey URL is the action button.
 */
class ServerProvisionFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Server $server,
        public ?string $errorExcerpt = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->server->name ?: 'Server';
        $ip = $this->server->ip_address ?: '—';
        $provider = $this->server->provider->label();
        $journeyUrl = URL::route('servers.journey', $this->server);

        $message = (new MailMessage)
            ->error()
            ->subject(sprintf('[%s] Server provisioning failed', $name))
            ->greeting(sprintf('Your server "%s" did not finish setting up.', $name))
            ->line('Provisioning ran into an error and could not be completed automatically. The server may still be visible in your provider account; if so you can delete it from dply or retry the provision from the journey page.')
            ->line(sprintf('Server: %s', $name))
            ->line(sprintf('Provider: %s', $provider))
            ->line(sprintf('Address: %s', $ip));

        if ($this->errorExcerpt !== null && trim($this->errorExcerpt) !== '') {
            $message->line('Last error excerpt:')
                ->line(Str::limit($this->errorExcerpt, 600));
        }

        return $message
            ->action('Open provisioning journey', $journeyUrl)
            ->line('The journey page shows which step failed, the SSH transcript, and a Retry button.')
            ->salutation('— dply');
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'ip' => $this->server->ip_address,
            'error_excerpt' => $this->errorExcerpt,
        ];
    }
}
