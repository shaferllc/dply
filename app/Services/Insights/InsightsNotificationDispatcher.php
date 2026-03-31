<?php

namespace App\Services\Insights;

use App\Models\InsightDigestQueue;
use App\Models\InsightFinding;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Team;
use Carbon\Carbon;

class InsightsNotificationDispatcher
{
    public const EVENT_KEY = 'server.insights_alerts';

    public function notifyIfSubscribed(Server $server, InsightFinding $finding, bool $wasReopened): void
    {
        $org = $server->organization;
        if (! $org instanceof Organization) {
            return;
        }

        $prefs = $org->mergedInsightsPreferences();
        $isCritical = $finding->severity === InsightFinding::SEVERITY_CRITICAL;

        if (! $isCritical && ($prefs['digest_non_critical'] ?? false)) {
            InsightDigestQueue::query()->firstOrCreate(
                [
                    'insight_finding_id' => $finding->id,
                ],
                [
                    'organization_id' => $org->id,
                ]
            );

            return;
        }

        if (! $isCritical && ($prefs['quiet_hours_enabled'] ?? false) && $this->inQuietHours($prefs)) {
            return;
        }

        $subs = NotificationSubscription::query()
            ->where('event_key', self::EVENT_KEY)
            ->where(function ($q) use ($server): void {
                $q->where(fn ($q2) => $q2
                    ->where('subscribable_type', Server::class)
                    ->where('subscribable_id', $server->id));
                if ($server->team_id !== null) {
                    $q->orWhere(fn ($q2) => $q2
                        ->where('subscribable_type', Team::class)
                        ->where('subscribable_id', $server->team_id));
                }
            })
            ->with('channel')
            ->get()
            ->unique('notification_channel_id');

        if ($subs->isEmpty()) {
            return;
        }

        $severityLabel = strtoupper($finding->severity);
        $subject = '['.config('app.name').'] ['.$severityLabel.'] '.$server->name.' — '.$finding->title;
        $lines = [
            '['.$severityLabel.'] '.$finding->title,
        ];
        if (is_string($finding->body) && $finding->body !== '') {
            $lines[] = $finding->body;
        }
        if ($finding->site_id) {
            $lines[] = __('Site ID: :id', ['id' => $finding->site_id]);
        }
        if ($wasReopened) {
            $lines[] = __('This issue recurred after being resolved.');
        }

        $playbook = config('insights_playbooks.'.$finding->insight_key);
        if (is_array($playbook) && ! empty($playbook['url'])) {
            $lines[] = ($playbook['label'] ?? __('Guide')).': '.$playbook['url'];
        }

        $lines[] = __('Severity: :s', ['s' => $finding->severity]);

        $text = implode("\n", $lines);
        $url = route('servers.insights', $server, absolute: true);

        foreach ($subs as $sub) {
            $channel = $sub->channel;
            if ($channel === null) {
                continue;
            }

            $channel->sendOperationalMessage($subject, $text, $url, __('Open Insights'));
        }
    }

    /**
     * @param  array<string, mixed>  $prefs
     */
    protected function inQuietHours(array $prefs): bool
    {
        $tz = config('app.timezone', 'UTC');
        $now = Carbon::now($tz);
        $start = (int) ($prefs['quiet_hours_start'] ?? 22);
        $end = (int) ($prefs['quiet_hours_end'] ?? 7);
        $h = (int) $now->format('G');

        if ($start === $end) {
            return false;
        }

        if ($start < $end) {
            return $h >= $start && $h < $end;
        }

        return $h >= $start || $h < $end;
    }
}
