<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Branded HTML email for email-type notification channels — both operational
 * alerts ({@see \App\Models\NotificationChannel::sendOperationalMessage()}) and
 * the "Test" button on the notification-channels settings page.
 *
 * Renders the markdown view, which Laravel emits as BOTH an HTML part (using the
 * branded `dply` mail theme) and an auto-generated plain-text part — replacing the
 * old text-only `Mail::raw` path that had no HTML part. Queued so the SMTP
 * round-trip stays off the request / dispatching-job thread, with retries.
 */
class NotificationChannelMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  list<string>  $bodyLines  paragraphs rendered under the heading
     * @param  string  $severity  info|warning|error — tints the accent panel/button
     */
    public function __construct(
        public string $heading,
        public array $bodyLines = [],
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
        public string $severity = 'info',
        public ?string $subjectLine = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine ?? $this->heading,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.notification-channel',
            with: [
                'heading' => $this->heading,
                'bodyLines' => $this->bodyLines,
                'actionUrl' => $this->actionUrl,
                'actionLabel' => $this->actionLabel ?? __('Open in dply'),
                'severity' => $this->severity,
            ],
        );
    }
}
