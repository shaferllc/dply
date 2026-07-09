<?php

namespace App\Modules\Notifications\Services;

use App\Models\EdgeDeployment;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Publishes deploy notifications for dply Edge deployments — the Edge mirror of
 * the VM deploy notifications emitted by {@see \App\Modules\Deploy\Jobs\RunSiteDeploymentJob}.
 *
 * Deliberately reuses the existing `site.deployment_started` / `site.deployments`
 * event keys rather than introducing an Edge-only rule table: subscriptions,
 * channels (email / Slack / Discord / webhook), the site Settings UI and
 * {@see NotificationWebhookDestinationRouter} then all work for Edge sites for
 * free, and an operator who subscribed to "Deployments" gets both VM and Edge.
 *
 * Status normalization matters: EdgeDeployment uses `live`, while the webhook
 * router maps `metadata['status']` on the `success` / `failed` / `skipped`
 * vocabulary that SiteDeployment speaks. We publish the normalized value so an
 * Edge deploy fires `deploy_success` on outbound webhooks like a VM deploy does.
 */
final class EdgeDeployNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    public function started(EdgeDeployment $deployment): void
    {
        if (! $this->enabled()) {
            return;
        }

        $site = $deployment->site;
        if (! $site instanceof Site) {
            return;
        }

        $this->publisher->publish(
            eventKey: 'site.deployment_started',
            subject: $deployment,
            title: '['.config('app.name').'] '.$site->name.' edge deploy started',
            body: 'Branch: '.($deployment->git_branch ?: 'main'),
            metadata: [
                'deployment_id' => $deployment->id,
                'site_id' => $site->id,
                'site_name' => $site->name,
                'trigger' => 'edge',
                'status' => 'running',
            ],
        );
    }

    /**
     * Terminal-state notification. Safe to call from every Edge failure path —
     * only `live` and `failed` publish, so a deployment superseded by a newer
     * one (or still building) stays quiet.
     */
    public function finished(EdgeDeployment $deployment): void
    {
        if (! $this->enabled()) {
            return;
        }

        $status = $this->normalizeStatus((string) $deployment->status);
        if ($status === null) {
            return;
        }

        $site = $deployment->site;
        if (! $site instanceof Site) {
            return;
        }

        $body = 'Branch: '.($deployment->git_branch ?: 'main');
        if ($deployment->git_commit) {
            $body .= "\nGit SHA: ".$deployment->git_commit;
        }
        if ($status === 'failed' && $deployment->failure_reason) {
            $body .= "\n\n".Str::limit((string) $deployment->failure_reason, 1200);
        }

        $this->publisher->publish(
            eventKey: 'site.deployments',
            subject: $deployment,
            title: '['.config('app.name').'] '.$site->name.' edge deploy '.strtoupper($status),
            body: $body,
            metadata: [
                'deployment_id' => $deployment->id,
                'site_id' => $site->id,
                'site_name' => $site->name,
                'status' => $status,
                'trigger' => 'edge',
                'git_sha' => $deployment->git_commit,
                'failure_reason' => $status === 'failed'
                    ? Str::limit((string) $deployment->failure_reason, 1200)
                    : null,
            ],
        );
    }

    /**
     * Map the EdgeDeployment vocabulary onto the SiteDeployment one the
     * notification/webhook layer already understands. Anything non-terminal
     * (building, publishing, superseded) returns null and is not published.
     */
    private function normalizeStatus(string $status): ?string
    {
        return match ($status) {
            EdgeDeployment::STATUS_LIVE => 'success',
            EdgeDeployment::STATUS_FAILED => 'failed',
            default => null,
        };
    }

    private function enabled(): bool
    {
        return (bool) config('dply.deploy_notifications', true);
    }
}
