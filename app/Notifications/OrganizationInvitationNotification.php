<?php

namespace App\Notifications;

use App\Models\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification implements ShouldQueue
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
        $orgName = (string) ($metadata['organization_name'] ?? __('your organization'));
        $inviterName = (string) ($metadata['inviter_name'] ?? __('Someone'));
        $role = (string) ($metadata['role'] ?? __('member'));
        $token = (string) ($metadata['invitation_token'] ?? '');
        $url = route('invitations.accept', ['token' => $token]);

        return (new MailMessage)
            ->subject($this->event->title ?: 'Invitation to join '.$orgName)
            ->line($inviterName.' has invited you to join **'.$orgName.'** on '.config('app.name').'.')
            ->line('You will be added as a '.$role)
            ->action('Accept invitation', $url)
            ->line('This invitation expires in 7 days.')
            ->line('If you did not expect this invitation, you can ignore this email.');
    }
}
