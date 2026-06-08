<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to org owners/admins when a point-in-time capture from the Snapshots
 * workspace finishes — either a site database snapshot or a full-disk server
 * image. Fires on both completion and failure so operators get a paper trail of
 * their captures without having to sit on the page. Mirrors the shape of
 * {@see BackupFailureNotification}.
 */
class SnapshotStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $kind  'database' | 'image'
     * @param  string  $status  one of Snapshot::STATUS_* / ServerImage::STATUS_* ('completed' | 'failed')
     * @param  string  $label  the snapshot's site name or the image's name
     * @param  string  $url  deep link back to the Snapshots workspace
     */
    public function __construct(
        public string $kind,
        public string $status,
        public string $label,
        public string $serverName,
        public string $url,
        public string $errorMessage = '',
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
        $failed = $this->status === 'failed';
        $noun = $this->kind === 'image' ? __('Server image') : __('Database snapshot');
        $verb = $failed ? __('failed') : __('completed');

        $mail = (new MailMessage)
            ->subject(sprintf('[%s] %s %s: %s', config('app.name'), $noun, $verb, $this->label));

        if ($failed) {
            $mail->error();
        }

        $mail->line(__(':noun ":label" on :server :verb.', [
            'noun' => $noun,
            'label' => $this->label,
            'server' => $this->serverName ?: __('your server'),
            'verb' => $verb,
        ]));

        if ($failed && filled($this->errorMessage)) {
            $mail->line(__('Error: :err', ['err' => $this->errorMessage]));
        }

        $mail->action(__('Open Snapshots'), $this->url);

        return $mail;
    }
}
