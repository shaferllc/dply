<?php

namespace App\Notifications;

use App\Models\NotificationEvent;
use App\Notifications\Concerns\DeliversToIntercom;
use App\Notifications\Concerns\DeliversToMicrosoftTeams;
use App\Notifications\Concerns\DeliversToPagerDuty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;
    use DeliversToPagerDuty;
    use Queueable;

    public function __construct(
        public NotificationEvent $event
    ) {
        // Ride the notification queue, not the default 'dply' control-plane
        // queue — an invitation email must not sit behind SSH/provisioning
        // work. Same convention as UniversalEventNotification.
        $this->onQueue((string) config('dply.notification_queue', 'default'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return array_merge(['mail'], $this->viaIntercom($notifiable), $this->viaMicrosoftTeams($notifiable), $this->viaPagerDuty($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $metadata = $this->event->metadata ?? [];
        $orgName = (string) ($metadata['organization_name'] ?? __('your organization'));
        $inviterName = (string) ($metadata['inviter_name'] ?? __('Someone'));
        $role = (string) ($metadata['role'] ?? __('member'));
        $teamName = (string) ($metadata['team_name'] ?? '');
        $token = (string) ($metadata['invitation_token'] ?? '');
        $url = route('invitations.accept', ['token' => $token]);

        $message = (new MailMessage)
            ->subject($this->event->title ?: 'Invitation to join '.$orgName)
            ->line($inviterName.' has invited you to join **'.$orgName.'** on '.config('app.name').'.')
            ->line('You will be added as a '.$role);

        if ($teamName !== '') {
            $message->line('You will also join the team **'.$teamName.'**.');
        }

        return $message
            ->action('Accept invitation', $url)
            ->line('This invitation expires in 7 days.')
            ->line('If you did not expect this invitation, you can ignore this email.');
    }
}
