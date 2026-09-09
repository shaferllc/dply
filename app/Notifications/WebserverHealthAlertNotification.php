<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Notifications\Concerns\DeliversToIntercom;
use App\Notifications\Concerns\DeliversToMicrosoftTeams;
use App\Notifications\Concerns\DeliversToPagerDuty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a webserver / edge-proxy health metric crosses (or recovers
 * from) a threshold. Edge-triggered: only sent on state TRANSITIONS, so
 * a sustained alarm doesn't spam every minute. Mirrors the shape of
 * {@see BackupFailureNotification}.
 *
 * Transition values:
 *   - 'tripped': metric just exceeded the threshold this scrape.
 *   - 'recovered': metric is back below threshold after being tripped.
 */
class WebserverHealthAlertNotification extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;

    // Aliased so the override below can build the standard message and then
    // flip it to a resolve, rather than duplicating the trait's assembly.
    use DeliversToPagerDuty {
        toPagerDuty as protected buildPagerDutyMessage;
    }
    use Queueable;

    public function __construct(
        public Server $server,
        public string $engine,
        public string $metric,
        public string $transition,        // 'tripped' | 'recovered'
        public string $severity,          // 'warning' | 'critical'
        public float $observedValue,
        public float $thresholdValue,
        public string $comparator,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return array_merge(['mail'], $this->viaIntercom($notifiable), $this->viaMicrosoftTeams($notifiable), $this->viaPagerDuty($notifiable));
    }

    /**
     * The notification's own severity vocabulary already matches PagerDuty's
     * ('warning' | 'critical'), so it passes straight through.
     *
     * Recovery still returns a severity — it has to, or viaPagerDuty() would
     * drop the message and the incident this class opened would never close.
     * toPagerDuty() turns it into a resolve rather than a trigger.
     */
    public function pagerDutySeverity(object $notifiable): ?string
    {
        return $this->severity === 'critical'
            ? PagerDutyMessage::SEVERITY_CRITICAL
            : PagerDutyMessage::SEVERITY_WARNING;
    }

    /**
     * Keyed on (server, engine, metric) — deliberately NOT on the transition,
     * because the recovery has to name the same incident the trip opened.
     */
    public function pagerDutyDedupKey(object $notifiable): ?string
    {
        return 'dply:webserver-health:'.$this->server->id.':'.$this->engine.':'.$this->metric;
    }

    public function pagerDutySource(object $notifiable): string
    {
        return (string) ($this->server->name ?: $this->server->id);
    }

    /**
     * The one notification in the app that can close its own incident.
     *
     * This class is edge-triggered on state transitions, so a 'recovered' event
     * is exactly the signal PagerDuty's resolve action exists for: the metric is
     * back under threshold, and whoever was paged should not have to close the
     * incident by hand.
     */
    public function toPagerDuty(object $notifiable): PagerDutyMessage
    {
        $message = $this->buildPagerDutyMessage($notifiable);

        if ($this->transition === 'recovered') {
            $message->resolve();
        }

        return $message;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isTrip = $this->transition === 'tripped';
        $severityLabel = ucfirst($this->severity);
        $statusVerb = $isTrip ? 'tripped' : 'recovered';
        $emoji = $isTrip ? ($this->severity === 'critical' ? '🔴' : '🟡') : '🟢';

        $subject = sprintf(
            '%s [%s] %s %s on %s/%s',
            $emoji,
            config('app.name'),
            $severityLabel,
            $statusVerb,
            $this->server->name,
            $this->engine,
        );

        $url = route('servers.webserver', $this->server->id, absolute: true);

        $mail = (new MailMessage)->subject($subject);

        if ($isTrip) {
            $mail->error()
                ->greeting(sprintf('%s alert: %s on %s', $severityLabel, $this->engine, $this->server->name))
                ->line(__(':metric crossed the configured :severity threshold.', [
                    'metric' => $this->metricLabel(),
                    'severity' => $this->severity,
                ]))
                ->line(__('Observed: :obs ; threshold: :cmp :val', [
                    'obs' => $this->formatValue($this->observedValue),
                    'cmp' => $this->comparator,
                    'val' => $this->formatValue($this->thresholdValue),
                ]));
        } else {
            $mail->success()
                ->greeting(sprintf('Recovered: %s on %s', $this->engine, $this->server->name))
                ->line(__(':metric is back below the :severity threshold.', [
                    'metric' => $this->metricLabel(),
                    'severity' => $this->severity,
                ]))
                ->line(__('Current value: :obs', ['obs' => $this->formatValue($this->observedValue)]));
        }

        $mail->action(__('Open webserver workspace'), $url);

        return $mail;
    }

    private function metricLabel(): string
    {
        return match ($this->metric) {
            'daemon_silent_seconds' => __('Daemon silent (no metrics reporting)'),
            'errors_5xx_per_min' => __('5xx errors per minute'),
            'active_connections' => __('Active connections'),
            default => $this->metric,
        };
    }

    private function formatValue(float $v): string
    {
        if (fmod($v, 1.0) === 0.0) {
            return number_format((int) $v);
        }

        return number_format($v, 2);
    }
}
