<?php

namespace App\Notifications;

use App\Models\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email for a worker-pool scaling moment (started / scaled / failed). Driven by
 * the NotificationEvent the {@see \App\Services\WorkerPools\WorkerPoolNotifier}
 * publishes, so the in-app inbox, webhooks and this mail all read the same copy.
 */
class WorkerPoolScaleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public NotificationEvent $event
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $metadata = $this->event->metadata ?? [];
        $poolName = (string) ($metadata['pool_name'] ?? 'Worker pool');
        $desired = $metadata['desired_count'] ?? null;
        $active = $metadata['active_count'] ?? null;
        $error = $metadata['error'] ?? null;

        $mail = (new MailMessage)
            ->subject($this->event->title ?: '['.config('app.name').'] Worker pool scaling')
            ->line('Pool: **'.$poolName.'**');

        if (filled($this->event->body)) {
            $mail->line((string) $this->event->body);
        }
        if ($desired !== null) {
            $mail->line('Desired workers: **'.$desired.'**');
        }
        if ($active !== null) {
            $mail->line('Active workers: **'.$active.'**');
        }
        if (filled($error)) {
            $mail->line('Error:')->line('```'.PHP_EOL.$error.PHP_EOL.'```');
        }
        if (filled($this->event->url)) {
            $mail->action('Open the worker pool in Dply', $this->event->url);
        }

        return $mail;
    }
}
