<?php

namespace App\Notifications;

use App\Models\SiteDeployment;
use App\Services\Notifications\DeployDigestBuffer;
use App\Support\DeployLogRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class SiteDeploymentCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SiteDeployment $deployment
    ) {
        $this->deployment->loadMissing('site.server');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $this->deployment->loadMissing('site.organization');
        $site = $this->deployment->site;
        $org = $site?->organization;
        if ($org && ! $org->wantsDeployEmailNotifications()) {
            return [];
        }
        $orgId = $site?->organization_id;
        if ($orgId && (int) config('dply.deploy_digest_hours', 0) > 0) {
            $site = $this->deployment->site;
            $line = sprintf(
                '%s — %s — %s',
                $site?->name ?? '#'.$this->deployment->site_id,
                strtoupper($this->deployment->status),
                $this->deployment->trigger
            );
            DeployDigestBuffer::record($orgId, $line);

            return [];
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = $this->deployment->site;
        $status = $this->deployment->status;
        $subject = '['.config('app.name').'] Deploy '.strtoupper($status).' — '.($site?->name ?? 'Site');

        $mail = (new MailMessage)
            ->subject($subject)
            ->line('Site: **'.($site?->name ?? '#'.$this->deployment->site_id).'**')
            ->line('Trigger: '.$this->deployment->trigger)
            ->line('Status: **'.$status.'**');

        if ($this->deployment->git_sha) {
            $mail->line('Git SHA: `'.$this->deployment->git_sha.'`');
        }

        if ($site?->server) {
            $mail->action('Open site in Dply', route('sites.show', [$site->server, $site], absolute: true));
        }

        $snippet = $this->deployment->log_output
            ? Str::limit(DeployLogRedactor::redact($this->deployment->log_output), 1200)
            : null;
        if ($snippet) {
            $mail->line('Log excerpt:')->line('```'.PHP_EOL.$snippet.PHP_EOL.'```');
        }

        return $mail;
    }
}
