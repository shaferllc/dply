<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\QuickDownload;
use App\Services\Servers\QuickDownloadNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

/**
 * Emailed to the user who requested a quick download once the artifact has been
 * built and staged. The link is a signed, login-gated route that streams the
 * file once then deletes it; it stays valid until the 4h staging window closes.
 * In-app delivery is handled separately via the notification inbox.
 */
class QuickDownloadReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public QuickDownload $quickDownload,
        public string $label,
        public string $downloadUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $size = $this->quickDownload->bytes
            ? Number::fileSize((int) $this->quickDownload->bytes)
            : null;

        $mail = (new MailMessage)
            ->subject('['.config('app.name').'] '.__('Your download is ready: :label', ['label' => $this->label]))
            ->line(__('Your :label download has been prepared and is ready to grab.', ['label' => $this->label]));

        if ($size !== null) {
            $mail->line(__('Size: :size', ['size' => $size]));
        }

        $window = QuickDownloadNotifier::retentionWindowLabel();

        return $mail
            ->action(__('Download now'), $this->downloadUrl)
            ->line(__('This link stays valid for :window and you can re-download as often as you need; after that it is automatically deleted. You may be asked to sign in first.', ['window' => $window]));
    }
}
