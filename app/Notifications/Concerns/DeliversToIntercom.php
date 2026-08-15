<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\NotificationChannel;
use App\Modules\Notifications\Channels\Intercom\IntercomMessage;
use App\Modules\Notifications\Services\IntercomClient;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Gives any Notification an Intercom leg without hand-writing toIntercom() on
 * each of the two dozen classes.
 *
 * The default toIntercom() is derived from the class's own toMail(), so a
 * notification gets Intercom support by adding `use DeliversToIntercom;` and
 * merging viaIntercom() into via(). Classes wanting different copy just declare
 * their own toIntercom() — the trait method loses to a real method on the class.
 *
 * viaIntercom() returning [] rather than ['intercom'] is what keeps this safe to
 * apply everywhere: notifiables with no Intercom channel and deployments with no
 * app token never enter the driver at all, so the mail leg is unaffected.
 *
 * Deliberately NOT applied to UniversalEventNotification or to
 * Feedback's FeedbackReportStatusChanged — see the comments on those classes.
 */
trait DeliversToIntercom
{
    /**
     * The Intercom channel that should receive this notification: the
     * notifiable's own first, then its organization's, so a user who has not set
     * one up personally still reaches the workspace their org configured.
     *
     * Deliberately not memoised on the instance. via() runs before a queued
     * notification is serialised, so a cached NotificationChannel here would be
     * written into the job payload — carrying an encrypted credential into the
     * queue and going stale if the channel is edited before the job runs. Two
     * indexed lookups are the cheaper trade.
     */
    public function intercomChannelFor(object $notifiable): ?NotificationChannel
    {
        $owners = [$notifiable];

        if (method_exists($notifiable, 'currentOrganization')) {
            $owners[] = $notifiable->currentOrganization();
        }

        foreach ($owners as $owner) {
            if (! is_object($owner) || ! method_exists($owner, 'notificationChannels')) {
                continue;
            }

            $channel = $owner->notificationChannels()
                ->where('type', NotificationChannel::TYPE_INTERCOM)
                ->first();

            if ($channel instanceof NotificationChannel) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * Merge into via(): `array_merge(['mail'], $this->viaIntercom($notifiable))`.
     *
     * @return list<string>
     */
    public function viaIntercom(object $notifiable): array
    {
        if ($this->intercomChannelFor($notifiable) !== null) {
            return ['intercom'];
        }

        // No channel row, but the deployment has an app-wide token and the
        // notifiable can name a recipient — the package's documented setup.
        if (IntercomClient::appTokenConfigured()
            && method_exists($notifiable, 'routeNotificationFor')
            && is_array($notifiable->routeNotificationFor('intercom', $this))) {
            return ['intercom'];
        }

        return [];
    }

    /**
     * Translate this notification's MailMessage into an Intercom message.
     *
     * MailMessage is the one representation every notification here already
     * builds, so deriving from it means Intercom copy cannot drift from e-mail
     * copy — and no class has to be edited twice when its wording changes.
     */
    public function toIntercom(object $notifiable): IntercomMessage
    {
        $channel = $this->intercomChannelFor($notifiable);

        $mail = method_exists($this, 'toMail') ? $this->toMail($notifiable) : null;
        $subject = $mail instanceof MailMessage && is_string($mail->subject) && $mail->subject !== ''
            ? $mail->subject
            : (string) config('app.name');

        $body = $mail instanceof MailMessage
            ? $this->intercomBodyFromMail($mail)
            : $subject;

        if ($channel instanceof NotificationChannel) {
            // Reuse the model's builder so a notification sent this way is shaped
            // exactly like an operational alert on the same channel.
            return $channel->intercomMessageFor($body, $subject);
        }

        return IntercomMessage::create($body)
            ->subject($subject)
            ->from((string) config('services.intercom.admin_id', ''));
    }

    /**
     * Flatten a MailMessage into plain text: intro lines, the action as a
     * labelled URL (Intercom in-app messages don't take a button), then outro.
     */
    protected function intercomBodyFromMail(MailMessage $mail): string
    {
        $lines = [];

        foreach ($mail->introLines as $line) {
            $lines[] = (string) $line;
        }

        if (is_string($mail->actionText) && $mail->actionText !== '' && is_string($mail->actionUrl)) {
            $lines[] = $mail->actionText.': '.$mail->actionUrl;
        }

        foreach ($mail->outroLines as $line) {
            $lines[] = (string) $line;
        }

        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        return $lines === [] ? (string) $mail->subject : implode("\n\n", $lines);
    }
}
