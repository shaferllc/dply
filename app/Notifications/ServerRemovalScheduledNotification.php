<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServerRemovalScheduledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Server $server,
        public string $scheduledForDisplay,
        public ?string $reason,
        public string $actorName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! config('dply.server_deletion_notify_org_admins', true)) {
            return [];
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $org = $this->server->organization?->name ?? __('your organization');

        return (new MailMessage)
            ->subject(__('[:server] Server removal scheduled', ['server' => $this->server->name]))
            ->line(__('A server in :org is scheduled for removal.', ['org' => $org]))
            ->line(__('Server: :name', ['name' => $this->server->name]))
            ->line(__('Removal window ends: :when', ['when' => $this->scheduledForDisplay]))
            ->line(__('Scheduled by: :who', ['who' => $this->actorName]))
            ->when(filled($this->reason), fn (MailMessage $m) => $m->line(__('Reason: :r', ['r' => $this->reason])))
            ->action(__('Open server'), route('servers.show', $this->server, absolute: true));
    }
}
