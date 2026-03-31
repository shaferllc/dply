<?php

namespace App\Notifications;

use App\Models\ServerAuthorizedKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SshKeyRotationDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ServerAuthorizedKey $authorizedKey
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $server = $this->authorizedKey->server;
        $name = $server?->name ?? __('Server');
        $url = $server ? route('servers.ssh-keys', $server) : url('/');

        return (new MailMessage)
            ->subject(__('SSH key review due: :name', ['name' => $this->authorizedKey->name]))
            ->line(__('The SSH key “:key” on server “:server” is due for review (review-after date reached).', [
                'key' => $this->authorizedKey->name,
                'server' => $name,
            ]))
            ->action(__('Open SSH keys'), $url);
    }
}
