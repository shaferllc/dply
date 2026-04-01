<?php

namespace App\Services\Insights;

use App\Models\InsightDigestQueue;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\Server;
use App\Services\Notifications\NotificationPublisher;
use Carbon\Carbon;

class InsightsNotificationDispatcher
{
    public const EVENT_KEY = 'server.insights_alerts';

    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    public function notifyIfSubscribed(Server $server, InsightFinding $finding, bool $wasReopened, string $insightState = 'opened'): void
    {
        $org = $server->organization;
        if (! $org instanceof Organization) {
            return;
        }

        $prefs = $org->mergedInsightsPreferences();
        $isCritical = $finding->severity === InsightFinding::SEVERITY_CRITICAL;
        $recipientUsers = $org->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->pluck('users.id')
            ->all();

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

        $severityLabel = strtoupper($finding->severity);
        $stateLabel = $insightState === 'resolved' ? 'RESOLVED' : $severityLabel;
        $subject = '['.config('app.name').'] ['.$stateLabel.'] '.$server->name.' — '.$finding->title;
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
        if ($insightState === 'resolved') {
            $lines[] = __('This issue is now resolved.');
        }

        $playbook = config('insights_playbooks.'.$finding->insight_key);
        if (is_array($playbook) && ! empty($playbook['url'])) {
            $lines[] = ($playbook['label'] ?? __('Guide')).': '.$playbook['url'];
        }

        $lines[] = __('Severity: :s', ['s' => $finding->severity]);

        $text = implode("\n", $lines);
        $url = route('servers.insights', $server, absolute: true);

        $this->publisher->publish(
            eventKey: self::EVENT_KEY,
            subject: $finding,
            title: $subject,
            body: $text,
            url: $url,
            metadata: [
                'server_id' => $server->id,
                'finding_id' => $finding->id,
                'insight_key' => $finding->insight_key,
                'severity' => $finding->severity,
                'was_reopened' => $wasReopened,
                'insight_state' => $insightState,
            ],
            contextOverrides: [
                'organization_id' => $org->id,
                'team_id' => $server->team_id,
                'resource_type' => Server::class,
                'resource_id' => (string) $server->getKey(),
            ],
            recipientUsers: $recipientUsers,
        );
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
