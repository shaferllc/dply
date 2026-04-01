<?php

namespace App\Notifications;

use App\Models\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SshKeyRotationDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public NotificationEvent $event
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $metadata = $this->event->metadata ?? [];
        $keyName = (string) ($metadata['authorized_key_name'] ?? __('SSH key'));
        $serverName = (string) ($metadata['server_name'] ?? __('Server'));

        return (new MailMessage)
            ->subject($this->event->title ?: __('SSH key review due: :name', ['name' => $keyName]))
            ->line(__('The SSH key “:key” on server “:server” is due for review (review-after date reached).', [
                'key' => $keyName,
                'server' => $serverName,
            ]))
            ->when(filled($this->event->url), fn (MailMessage $m) => $m->action(__('Open SSH keys'), $this->event->url));
    }
}
