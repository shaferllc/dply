<?php

namespace App\Notifications;

use App\Models\NotificationEvent;
use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Notifications\Concerns\DeliversToIntercom;
use App\Notifications\Concerns\DeliversToMicrosoftTeams;
use App\Notifications\Concerns\DeliversToPagerDuty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SshKeyRotationDueNotification extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;
    use DeliversToPagerDuty;
    use Queueable;

    public function __construct(
        public NotificationEvent $event
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return array_merge(['mail'], $this->viaIntercom($notifiable), $this->viaMicrosoftTeams($notifiable), $this->viaPagerDuty($notifiable));
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
            ->when($this->event->url !== '', fn (MailMessage $m) => $m->action(__('Open SSH keys'), $this->event->url));
    }

    /**
     * Warning: a key past its rotation date is a standing risk to work through,
     * not something to wake someone for.
     */
    public function pagerDutySeverity(object $notifiable): ?string
    {
        return PagerDutyMessage::SEVERITY_WARNING;
    }

    /**
     * Keyed on the resource, so the daily reminder updates one incident instead
     * of opening a new one every day until someone rotates the key.
     */
    public function pagerDutyDedupKey(object $notifiable): ?string
    {
        return 'dply:ssh-key-rotation:'.$this->event->resource_type.':'.$this->event->resource_id;
    }
}
