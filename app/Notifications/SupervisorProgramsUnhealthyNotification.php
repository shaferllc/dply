<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupervisorProgramsUnhealthyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Server $server,
        public string $summary,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! config('dply.supervisor_health_notify_org_admins', true)) {
            return [];
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $org = $this->server->organization?->name ?? __('your organization');

        return (new MailMessage)
            ->subject(__('[:server] Supervisor programs need attention', ['server' => $this->server->name]))
            ->line(__('A scheduled health check reported an issue with Supervisor-managed programs on :server.', ['server' => $this->server->name]))
            ->line(__('Organization: :org', ['org' => $org]))
            ->line($this->summary)
            ->action(__('Open Daemons'), route('servers.daemons', $this->server, absolute: true));
    }
}
