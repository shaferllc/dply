<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Services\Notifications\NotificationPublisher;
use App\Support\Servers\SharedHostLlmAdvisor;
use App\Support\Servers\SharedHostReport;

final class SharedHostNotificationDispatcher
{
    public const EVENT_KEY = 'server.shared_host_alerts';

    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly SharedHostReport $report,
        private readonly SharedHostLlmAdvisor $llmAdvisor,
    ) {}

    /**
     * @param  array<string, mixed>  $breach
     */
    public function notifyBudgetBreach(Server $server, array $breach): void
    {
        $org = $server->organization;
        if (! $org instanceof Organization) {
            return;
        }

        $recipientUsers = $org->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->pluck('users.id')
            ->all();

        $subject = '['.config('app.name').'] '.$server->name.' — '.$breach['title'];
        $body = (string) ($breach['message'] ?? '');
        $body .= "\n\n".$this->briefing($server);
        $body .= "\n".__('Open Shared Host Radar: :url', [
            'url' => route('servers.shared-host', $server, absolute: true),
        ]);

        $this->publisher->publish(
            eventKey: self::EVENT_KEY,
            subject: $server,
            title: $subject,
            body: $body,
            url: route('servers.shared-host', $server, absolute: true),
            metadata: [
                'server_id' => $server->id,
                'site_slug' => (string) ($breach['slug'] ?? ''),
                'metric' => (string) ($breach['metric'] ?? ''),
                'observed_pct' => (float) ($breach['observed_pct'] ?? 0),
                'budget_pct' => (float) ($breach['budget_pct'] ?? 0),
                'breach_id' => (string) ($breach['id'] ?? ''),
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
     * @param  array<string, mixed>  $event
     */
    public function notifyContentionEvent(Server $server, array $event): void
    {
        $org = $server->organization;
        if (! $org instanceof Organization) {
            return;
        }

        $recipientUsers = $org->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->pluck('users.id')
            ->all();

        $subject = '['.config('app.name').'] '.$server->name.' — '.(string) ($event['title'] ?? __('Shared host alert'));
        $body = (string) ($event['message'] ?? '');
        $body .= "\n\n".$this->briefing($server);
        $body .= "\n".route('servers.shared-host', $server, absolute: true);

        $this->publisher->publish(
            eventKey: self::EVENT_KEY,
            subject: $server,
            title: $subject,
            body: $body,
            url: route('servers.shared-host', $server, absolute: true),
            metadata: [
                'server_id' => $server->id,
                'event_id' => (string) ($event['id'] ?? ''),
                'site_slug' => (string) ($event['site_slug'] ?? ''),
                'severity' => (string) ($event['severity'] ?? 'warning'),
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

    private function briefing(Server $server): string
    {
        $report = $this->report->forServer($server);

        return $this->llmAdvisor->notificationBriefing($server, $report);
    }
}
