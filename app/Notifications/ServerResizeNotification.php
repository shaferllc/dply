<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationEvent;
use App\Notifications\Concerns\DeliversToIntercom;
use App\Notifications\Concerns\DeliversToMicrosoftTeams;
use App\Notifications\Concerns\DeliversToPagerDuty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One class for all three resize moments (started / completed / failed) —
 * they share every field and differ only in wording, so three near-identical
 * notification classes would be three places to keep in sync.
 *
 * The phase comes from the event's metadata rather than a constructor flag so
 * a queued notification can never disagree with the event row it renders.
 */
class ServerResizeNotification extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;
    use DeliversToPagerDuty;
    use Queueable;

    public function __construct(
        public NotificationEvent $event,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return array_merge(
            ['mail'],
            $this->viaIntercom($notifiable),
            $this->viaMicrosoftTeams($notifiable),
            $this->viaPagerDuty($notifiable),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $meta = $this->event->metadata ?? [];
        $phase = (string) ($meta['phase'] ?? 'started');
        $server = (string) ($meta['server_name'] ?? __('Server'));
        $org = (string) ($meta['organization_name'] ?? __('your organization'));
        $from = (string) ($meta['from_size'] ?? '?');
        $to = (string) ($meta['to_size'] ?? '?');
        $siteNames = is_array($meta['site_names'] ?? null) ? $meta['site_names'] : [];
        $siteCount = (int) ($meta['site_count'] ?? count($siteNames));

        $mail = (new MailMessage)->subject($this->event->title ?: __('[:server] Server resize', ['server' => $server]));

        $mail = match ($phase) {
            'completed' => $mail
                ->line(__(':server in :org finished resizing.', ['server' => $server, 'org' => $org]))
                ->line(__('Now running :to (was :from).', ['to' => $to, 'from' => $from]))
                ->when($siteCount > 0, fn (MailMessage $m) => $m->line(
                    trans_choice('{1}1 site is back online.|[2,*]:count sites are back online.', $siteCount, ['count' => $siteCount])
                )),
            'failed' => $mail
                ->error()
                ->line(__('The resize of :server in :org failed.', ['server' => $server, 'org' => $org]))
                ->line(__('Attempted :from → :to.', ['from' => $from, 'to' => $to]))
                ->when(filled($meta['error'] ?? null), fn (MailMessage $m) => $m->line(__('Error: :e', ['e' => $meta['error']])))
                ->line(__('The machine may still be powered off — check it before assuming traffic has recovered.')),
            default => $mail
                ->line(__(':server in :org is being resized from :from to :to.', [
                    'server' => $server, 'org' => $org, 'from' => $from, 'to' => $to,
                ]))
                ->line($this->downtimeLine($meta, $siteCount))
                ->when($siteNames !== [], fn (MailMessage $m) => $m->line(
                    __('Affected sites: :sites', ['sites' => implode(', ', array_slice($siteNames, 0, 20))])
                )),
        };

        return $mail->when(
            filled($this->event->url),
            fn (MailMessage $m) => $m->action(__('Open server'), (string) $this->event->url),
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function downtimeLine(array $meta, int $siteCount): string
    {
        // Vultr reboots in place; the others stop and start the machine. The
        // difference is minutes vs. a reboot, and it changes what to expect.
        $powerCycle = (bool) ($meta['power_cycle'] ?? true);

        if ($siteCount === 0) {
            return $powerCycle
                ? __('The machine will be powered off for the resize and started again afterwards.')
                : __('The machine will reboot while the new plan is applied.');
        }

        return $powerCycle
            ? trans_choice(
                '{1}1 site on this server will be offline until the resize finishes.|[2,*]:count sites on this server will be offline until the resize finishes.',
                $siteCount,
                ['count' => $siteCount],
            )
            : trans_choice(
                '{1}1 site on this server will be briefly unreachable while it reboots.|[2,*]:count sites on this server will be briefly unreachable while it reboots.',
                $siteCount,
                ['count' => $siteCount],
            );
    }
}
