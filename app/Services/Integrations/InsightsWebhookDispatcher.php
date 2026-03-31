<?php

namespace App\Services\Integrations;

use App\Models\InsightFinding;
use App\Models\IntegrationOutboundWebhook;
use App\Models\Organization;
use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InsightsWebhookDispatcher
{
    public const EVENT_INSIGHT_OPENED = 'insight_opened';

    public const EVENT_INSIGHT_RESOLVED = 'insight_resolved';

    public function dispatchInsightOpened(Organization $organization, Server $server, InsightFinding $finding): void
    {
        $text = sprintf(
            '[%s] OPEN · %s · %s · %s',
            config('app.name'),
            $server->name,
            strtoupper($finding->severity),
            $finding->title
        );

        $this->dispatchToHooks($organization, self::EVENT_INSIGHT_OPENED, $text);
    }

    public function dispatchInsightResolved(Organization $organization, Server $server, InsightFinding $finding): void
    {
        $text = sprintf(
            '[%s] RESOLVED · %s · %s · %s',
            config('app.name'),
            $server->name,
            strtoupper($finding->severity),
            $finding->title
        );

        $this->dispatchToHooks($organization, self::EVENT_INSIGHT_RESOLVED, $text);
    }

    protected function dispatchToHooks(Organization $organization, string $event, string $text): void
    {
        $hooks = IntegrationOutboundWebhook::query()
            ->where('organization_id', $organization->id)
            ->where('enabled', true)
            ->whereNull('site_id')
            ->get();

        foreach ($hooks as $hook) {
            if (! $hook->wantsEvent($event)) {
                continue;
            }
            $this->sendOne($hook, $text);
        }
    }

    protected function sendOne(IntegrationOutboundWebhook $hook, string $text): void
    {
        $url = $hook->webhook_url;
        if ($url === null || $url === '') {
            return;
        }

        try {
            $payload = match ($hook->driver) {
                IntegrationOutboundWebhook::DRIVER_DISCORD => ['content' => $text],
                IntegrationOutboundWebhook::DRIVER_TEAMS => [
                    '@type' => 'MessageCard',
                    '@context' => 'https://schema.org/extensions',
                    'summary' => 'Insight',
                    'title' => 'Insight opened',
                    'text' => $text,
                ],
                default => ['text' => $text],
            };

            $response = Http::timeout(10)->acceptJson()->post($url, $payload);
            if (! $response->successful()) {
                Log::warning('Insights outbound webhook failed', [
                    'hook_id' => $hook->id,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Insights outbound webhook exception', [
                'hook_id' => $hook->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
