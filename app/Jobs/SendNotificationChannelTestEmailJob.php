<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\NotificationChannelMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a notification-channel "test" email from the worker, never the web
 * request. Resolving a mailer eagerly builds its Symfony transport, so doing it
 * inline in the Livewire request (via Mail::to(...)) crashes the page when the
 * configured default mailer is misconfigured — e.g. a null Cloudflare key. By
 * queueing, mailer resolution and any transport/credential failure stay on the
 * worker, where mail delivery belongs, and the web request only validates +
 * enqueues.
 */
class SendNotificationChannelTestEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $toAddress,
        public string $channelLabel,
        public string $actorLabel,
    ) {}

    public function handle(): void
    {
        Mail::to($this->toAddress)->send(new NotificationChannelMail(
            heading: __('Notification channel test'),
            bodyLines: [
                __('This confirms the “:label” channel can receive :app alerts.', ['label' => $this->channelLabel, 'app' => config('app.name')]),
                __('Triggered by :actor.', ['actor' => $this->actorLabel]),
            ],
            subjectLine: __('[:app] Notification channel test', ['app' => config('app.name')]),
        ));
    }
}
