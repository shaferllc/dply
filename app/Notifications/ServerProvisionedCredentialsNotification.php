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
 * Sent to the server's creator when provisioning successfully completes,
 * IF the org has `email_server_credentials_enabled` toggled on.
 *
 * Carries the SSH connection block (host, port, user) plus a link back
 * to the dply server overview where the private key can be downloaded
 * over an authenticated session. The key itself is intentionally NOT
 * embedded in the email — duplicating it across mailboxes weakens the
 * security posture vs. the dashboard which is already gated by login.
 */
class ServerProvisionedCredentialsNotification extends Notification implements ShouldQueue
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
        $name = $this->server->name ?: 'Server';
        $ip = $this->server->ip_address ?: '—';
        $port = (int) ($this->server->ssh_port ?: 22);
        $sshUser = $this->server->ssh_user ?: 'dply';
        $overviewUrl = URL::route('servers.overview', $this->server);

        $sshCommand = "ssh -p {$port} {$sshUser}@{$ip}";

        return (new MailMessage)
            ->subject(sprintf('[%s] Server is ready — connection details inside', $name))
            ->greeting(sprintf('Your server "%s" is up.', $name))
            ->line('Connection details:')
            ->line(sprintf('Host: %s', $ip))
            ->line(sprintf('Port: %d', $port))
            ->line(sprintf('User: %s', $sshUser))
            ->line(sprintf('Command: %s', $sshCommand))
            ->line('The SSH private key is available from the dply dashboard. Sign in below to download it:')
            ->action('Open server in dply', $overviewUrl)
            ->line(
                'For security, the private key is not included in this email — '.
                'sending it by email would duplicate it across mailboxes and weaken access control.'
            )
            ->salutation('— dply');
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'ip' => $this->server->ip_address,
        ];
    }
}
