<?php

namespace App\Notifications;

use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public OrganizationInvitation $invitation
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('invitations.accept', ['token' => $this->invitation->token]);

        return (new MailMessage)
            ->subject('Invitation to join '.$this->invitation->organization->name)
            ->line($this->invitation->inviter->name.' has invited you to join **'.$this->invitation->organization->name.'** on '.config('app.name').'.')
            ->line('You will be added as a '.$this->invitation->role.')
            ->action('Accept invitation', $url)
            ->line('This invitation expires in 7 days.')
            ->line('If you did not expect this invitation, you can ignore this email.');
    }
}
