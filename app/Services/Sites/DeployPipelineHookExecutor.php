<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Runs pipeline hooks: remote shell, outbound webhooks, and notification channels.
 */
final class DeployPipelineHookExecutor
{
    public function __construct(
        private readonly DeployHookScriptExpander $expander,
        private readonly NotificationPublisher $notificationPublisher,
    ) {}

    public function run(
        SiteDeployHook $hook,
        Site $site,
        string $workingDirectory,
        ?RemoteShell $ssh = null,
        ?SiteDeployment $deployment = null,
        bool $stepSucceeded = true,
    ): string {
        return match ($hook->hook_kind) {
            SiteDeployHook::KIND_WEBHOOK => $this->runWebhook($hook, $site, $deployment, $stepSucceeded),
            SiteDeployHook::KIND_NOTIFICATION => $this->runNotification($hook, $site, $deployment, $stepSucceeded),
            default => $this->runShell($hook, $site, $workingDirectory, $ssh),
        };
    }

    private function runShell(SiteDeployHook $hook, Site $site, string $workingDirectory, ?RemoteShell $ssh): string
    {
        if ($ssh === null) {
            return "\n--- hook {$hook->id} skipped (no SSH) ---\n";
        }

        $script = trim((string) $hook->script);
        if ($script === '') {
            return '';
        }

        $script = $this->expander->expand($script, $site);
        $default = (int) config('dply.default_deploy_hook_timeout_seconds', 900);
        $timeout = max(30, min(3600, (int) ($hook->timeout_seconds ?? $default)));

        $b64 = base64_encode($script);
        $output = $ssh->exec(
            sprintf(
                'cd %s && echo %s | base64 -d | /usr/bin/env bash 2>&1; printf "\\nDPLY_HOOK_EXIT:%%s" "$?"',
                escapeshellarg($workingDirectory),
                escapeshellarg($b64)
            ),
            $timeout
        );

        return "\n--- hook {$hook->anchor} #{$hook->id} (shell) ---\n".$output;
    }

    private function runWebhook(
        SiteDeployHook $hook,
        Site $site,
        ?SiteDeployment $deployment,
        bool $stepSucceeded,
    ): string {
        $url = trim((string) $hook->webhook_url);
        if ($url === '') {
            return "\n--- hook #{$hook->id} webhook skipped (no URL) ---\n";
        }

        $payload = [
            'event' => 'site.pipeline.hook',
            'hook_id' => (string) $hook->id,
            'site_id' => (string) $site->id,
            'site_name' => $site->name,
            'anchor' => $hook->anchor,
            'anchor_step_id' => $hook->anchor_step_id,
            'deployment_id' => $deployment?->id,
            'deployment_status' => $deployment?->status,
            'ok' => $stepSucceeded,
            'occurred_at' => now()->toIso8601String(),
        ];

        try {
            $response = Http::timeout(15)->post($url, $payload);
            $status = $response->status();

            return "\n--- hook #{$hook->id} webhook HTTP {$status} ---\n";
        } catch (\Throwable $e) {
            Log::warning('Deploy pipeline webhook failed', [
                'hook_id' => $hook->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return "\n--- hook #{$hook->id} webhook error: {$e->getMessage()} ---\n";
        }
    }

    private function runNotification(
        SiteDeployHook $hook,
        Site $site,
        ?SiteDeployment $deployment,
        bool $stepSucceeded,
    ): string {
        $channel = $hook->notificationChannel;
        if (! $channel instanceof NotificationChannel) {
            return "\n--- hook #{$hook->id} notification skipped (no channel) ---\n";
        }

        $eventKey = $hook->notification_event ?: SiteDeployHook::NOTIFICATION_EVENT_DEPLOY_OUTCOME;
        $title = __('Pipeline hook: :site', ['site' => $site->name]);
        $body = match ($hook->anchor) {
            SiteDeployHook::ANCHOR_AFTER_STEP => $stepSucceeded
                ? __('Step completed successfully.')
                : __('Step failed — check deployment log.'),
            SiteDeployHook::ANCHOR_BEFORE_CLONE => __('Deploy pipeline starting (before clone).'),
            SiteDeployHook::ANCHOR_AFTER_ACTIVATE => __('Release activated on server.'),
            default => __('Hook ran after clone.'),
        };

        if ($hook->hook_kind === SiteDeployHook::KIND_NOTIFICATION && $eventKey === SiteDeployHook::NOTIFICATION_EVENT_DEPLOY_OUTCOME) {
            $channel->sendOperationalMessage(
                $title,
                $body,
                $deployment ? route('sites.deployments.show', [
                    'server' => $site->server_id,
                    'site' => $site->id,
                    'deployment' => $deployment->id,
                ], false) : null,
            );
        } else {
            $this->notificationPublisher->publish(
                $eventKey,
                $site,
                $title,
                $body,
                metadata: [
                    'hook_id' => (string) $hook->id,
                    'anchor' => $hook->anchor,
                    'deployment_id' => $deployment?->id,
                    'ok' => $stepSucceeded,
                ],
            );
        }

        return "\n--- hook #{$hook->id} notification sent ---\n";
    }
}
