<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Site;
use App\Notifications\Concerns\DeliversToIntercom;
use App\Notifications\Concerns\DeliversToMicrosoftTeams;
use App\Notifications\Concerns\DeliversToPagerDuty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Dedicated Redis/DB VM failed: the orphan box was removed so it does not keep
 * billing. Points at the site Resources tab to try again.
 */
class DedicatedResourceProvisionFailedNotification extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;
    use DeliversToPagerDuty;
    use Queueable;

    public function __construct(
        public Site $site,
        public string $resourceLabel,
        public string $errorExcerpt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return array_merge(['mail'], $this->viaIntercom($notifiable), $this->viaMicrosoftTeams($notifiable), $this->viaPagerDuty($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = $this->site->name ?: $this->site->slug ?: 'site';
        $server = $this->site->server;
        $url = $server
            ? route('sites.show', ['server' => $server, 'site' => $this->site, 'section' => 'resources'])
            : url('/');

        $message = (new MailMessage)
            ->error()
            ->subject(sprintf('[%s] %s provision failed', $siteName, $this->resourceLabel))
            ->greeting(sprintf('%s for "%s" did not come up.', $this->resourceLabel, $siteName))
            ->line('The failed server was removed so it will not keep running or billing. You can retry from the site Resources tab.');

        if (trim($this->errorExcerpt) !== '') {
            $message->line('Error:')->line(Str::limit($this->errorExcerpt, 600));
        }

        return $message
            ->action('Open Resources', $url)
            ->salutation('— dply');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'site_id' => $this->site->id,
            'resource' => $this->resourceLabel,
            'error_excerpt' => $this->errorExcerpt,
        ];
    }
}
