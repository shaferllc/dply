<?php

namespace App\Services\Integrations;

use App\Models\IntegrationOutboundWebhook;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeployIntegrationDispatcher
{
    public function dispatch(Organization $organization, ?Site $site, SiteDeployment $deployment): void
    {
        $event = match ($deployment->status) {
            'success' => 'deploy_success',
            'failed' => 'deploy_failed',
            'skipped' => 'deploy_skipped',
            default => null,
        };
        if ($event === null) {
            return;
        }

        $hooks = IntegrationOutboundWebhook::query()
            ->where('organization_id', $organization->id)
            ->where('enabled', true)
            ->where(function ($q) use ($site) {
                $q->whereNull('site_id');
                if ($site) {
                    $q->orWhere('site_id', $site->id);
                }
            })
            ->get();

        foreach ($hooks as $hook) {
            if (! $hook->wantsEvent($event)) {
                continue;
            }
            $this->sendOne($hook, $organization, $site, $deployment, $event);
        }
    }

    protected function sendOne(
        IntegrationOutboundWebhook $hook,
        Organization $organization,
        ?Site $site,
        SiteDeployment $deployment,
        string $event
    ): void {
        $url = $hook->webhook_url;
        if ($url === null || $url === '') {
            return;
        }

        $text = sprintf(
            '[%s] %s · %s · %s · trigger=%s',
            config('app.name'),
            $organization->name,
            $site?->name ?? '—',
            strtoupper($deployment->status),
            $deployment->trigger
        );
        if ($deployment->git_sha) {
            $text .= ' · '.$deployment->git_sha;
        }

        try {
            $payload = match ($hook->driver) {
                IntegrationOutboundWebhook::DRIVER_DISCORD => ['content' => $text],
                IntegrationOutboundWebhook::DRIVER_TEAMS => [
                    '@type' => 'MessageCard',
                    '@context' => 'https://schema.org/extensions',
                    'summary' => 'Deploy '.$deployment->status,
                    'themeColor' => $deployment->status === 'success' ? '2DC26B' : 'E74856',
                    'title' => 'Deployment '.$deployment->status,
                    'text' => $text,
                ],
                default => ['text' => $text],
            };

            $response = Http::timeout(10)->acceptJson()->post($url, $payload);
            if (! $response->successful()) {
                Log::warning('Deploy integration webhook failed', [
                    'hook_id' => $hook->id,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Deploy integration webhook exception', [
                'hook_id' => $hook->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
