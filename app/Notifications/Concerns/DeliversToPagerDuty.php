<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\NotificationChannel;
use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Modules\Notifications\Services\PagerDutyClient;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Gives a Notification a PagerDuty leg — but only if it asks for one.
 *
 * This is the deliberate difference from DeliversToIntercom, which adds a leg to
 * every class that uses it. PagerDuty pages a human, so the default here is
 * SILENCE: viaPagerDuty() returns [] unless the class overrides
 * pagerDutySeverity() with a non-null severity. A notification saying "your
 * download is ready" must never raise an incident, and the safe default is the
 * one you get by forgetting to think about it.
 *
 * To make a notification page:
 *
 *     public function pagerDutySeverity(object $notifiable): ?string
 *     {
 *         return PagerDutyMessage::SEVERITY_ERROR;
 *     }
 *
 * Return null (or don't override) and nothing is sent, whatever channels exist.
 * Conditional paging works too — return a severity only when a run failed.
 */
trait DeliversToPagerDuty
{
    /**
     * Severity this notification should page at, or null to stay silent.
     *
     * Null by default on purpose: opting in is a decision someone has to make
     * per notification, and the cost of a wrong "yes" (a 3am page for nothing)
     * is far higher than a wrong "no".
     */
    public function pagerDutySeverity(object $notifiable): ?string
    {
        return null;
    }

    /**
     * The PagerDuty channel that should receive this: the notifiable's own
     * first, then its organization's. Mirrors DeliversToIntercom's resolution.
     */
    public function pagerDutyChannelFor(object $notifiable): ?NotificationChannel
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
                ->where('type', NotificationChannel::TYPE_PAGERDUTY)
                ->first();

            if ($channel instanceof NotificationChannel) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * Merge into via(): `array_merge(['mail'], $this->viaPagerDuty($notifiable))`.
     *
     * @return list<string>
     */
    public function viaPagerDuty(object $notifiable): array
    {
        if ($this->pagerDutySeverity($notifiable) === null) {
            return [];
        }

        if ($this->pagerDutyChannelFor($notifiable) !== null || PagerDutyClient::appRoutingKeyConfigured()) {
            return ['PagerDuty'];
        }

        return [];
    }

    /**
     * Build the event. Summary comes from the mail subject — an incident title
     * wants one line, not the whole body, and the body goes to custom_details
     * where PagerDuty renders it as a detail table.
     */
    public function toPagerDuty(object $notifiable): PagerDutyMessage
    {
        $channel = $this->pagerDutyChannelFor($notifiable);
        $mail = method_exists($this, 'toMail') ? $this->toMail($notifiable) : null;

        $summary = $mail instanceof MailMessage && is_string($mail->subject) && $mail->subject !== ''
            ? $mail->subject
            : class_basename(static::class);

        $details = $mail instanceof MailMessage ? $this->pagerDutyDetailsFromMail($mail) : '';
        $actionUrl = $mail instanceof MailMessage && is_string($mail->actionUrl) ? $mail->actionUrl : null;

        $context = [
            'severity' => $this->pagerDutySeverity($notifiable),
            'event_key' => class_basename(static::class),
            'dedup_key' => $this->pagerDutyDedupKey($notifiable),
            'source' => $this->pagerDutySource($notifiable),
        ];

        if ($channel instanceof NotificationChannel) {
            return $channel->pagerDutyMessageFor($summary, $details, $actionUrl, $context);
        }

        $message = PagerDutyMessage::create()
            ->setRoutingKey(PagerDutyClient::appRoutingKey())
            ->setSummary($summary)
            ->setSource($context['source'])
            ->setSeverity(PagerDutyMessage::severityFromEventSeverity($context['severity']))
            ->setClass($context['event_key'])
            ->setClient((string) config('app.name'));

        if ($context['dedup_key'] !== null) {
            $message->setDedupKey($context['dedup_key']);
        }

        if ($details !== '') {
            $message->addCustomDetail('details', $details);
        }

        return $message;
    }

    /**
     * Override to collapse repeats of the same condition into one incident.
     * Null means every send opens a fresh incident, which is the right default
     * for a one-shot event (a provision failed once) but wrong for anything
     * that can flap.
     */
    public function pagerDutyDedupKey(object $notifiable): ?string
    {
        return null;
    }

    /** Override with the thing that actually broke — a server or site name. */
    public function pagerDutySource(object $notifiable): string
    {
        return (string) config('app.name');
    }

    protected function pagerDutyDetailsFromMail(MailMessage $mail): string
    {
        $lines = [];

        foreach ($mail->introLines as $line) {
            $lines[] = (string) $line;
        }

        foreach ($mail->outroLines as $line) {
            $lines[] = (string) $line;
        }

        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        return implode("\n", $lines);
    }
}
