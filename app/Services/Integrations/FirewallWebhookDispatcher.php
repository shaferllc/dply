<?php

namespace App\Services\Integrations;

use App\Models\IntegrationOutboundWebhook;
use App\Models\Organization;
use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Outbound hooks must explicitly list event names (e.g. firewall_applied) — unlike deploy hooks,
 * empty “all events” does not imply firewall (avoids spamming deploy channels).
 */
class FirewallWebhookDispatcher
{
    public function dispatch(Organization $organization, Server $server, string $event, string $text): void
    {
        $hooks = IntegrationOutboundWebhook::query()
            ->where('organization_id', $organization->id)
            ->where('enabled', true)
            ->whereNull('site_id')
            ->get();

        foreach ($hooks as $hook) {
            $events = $hook->events;
            if (! is_array($events) || ! in_array($event, $events, true)) {
                continue;
            }
            $this->sendOne($hook, $organization, $server, $event, $text);
        }
    }

    protected function sendOne(
        IntegrationOutboundWebhook $hook,
        Organization $organization,
        Server $server,
        string $event,
        string $text
    ): void {
        $url = $hook->webhook_url;
        if ($url === null || $url === '') {
            return;
        }

        $body = sprintf('[%s] %s · %s · %s', config('app.name'), $organization->name, $server->name, $text);

        try {
            $payload = match ($hook->driver) {
                IntegrationOutboundWebhook::DRIVER_DISCORD => ['content' => $body],
                IntegrationOutboundWebhook::DRIVER_TEAMS => [
                    '@type' => 'MessageCard',
                    '@context' => 'https://schema.org/extensions',
                    'summary' => $event,
                    'title' => $event,
                    'text' => $body,
                ],
                default => ['text' => $body],
            };

            $response = Http::timeout(10)->acceptJson()->post($url, $payload);
            if (! $response->successful()) {
                Log::warning('Firewall integration webhook failed', [
                    'hook_id' => $hook->id,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Firewall integration webhook exception', [
                'hook_id' => $hook->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
