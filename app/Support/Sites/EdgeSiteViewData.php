<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Services\Edge\EdgeDeliveryContextResolver;
use App\Support\Edge\EdgeLocalDevDiagnostics;
use App\Support\Edge\EdgePlatformCredentials;
use App\Support\Edge\FakeEdgeProvision;

/**
 * Shared Edge site dashboard variables for settings + show views.
 */
final class EdgeSiteViewData
{
    /**
     * @return array<string, mixed>
     */
    public static function context(Site $site): array
    {
        $edgeMeta = $site->edgeMeta();
        $edgeSourceSpec = is_array($edgeMeta['source'] ?? null) ? $edgeMeta['source'] : null;
        $edgeBuildSpec = is_array($edgeMeta['build'] ?? null) ? $edgeMeta['build'] : [];
        $edgeLiveUrl = $site->edgeLiveUrl();
        $edgeRepo = (string) ($edgeSourceSpec['repo'] ?? '');
        $edgeBranch = (string) ($edgeSourceSpec['branch'] ?? 'main');
        $edgeSourceRef = $edgeRepo !== '' ? $edgeRepo.'@'.$edgeBranch : '';
        $edgeBuildCommand = (string) ($edgeBuildSpec['command'] ?? 'npm ci && npm run build');
        $edgeOutputDir = (string) ($edgeBuildSpec['output_dir'] ?? 'dist');
        $edgeDeployOnPush = (bool) ($edgeSourceSpec['deploy_on_push'] ?? true);
        $edgeWebhookMeta = is_array($edgeMeta['webhook'] ?? null) ? $edgeMeta['webhook'] : null;
        $edgeGithubWebhookConnected = is_array($edgeWebhookMeta) && ($edgeWebhookMeta['hook_id'] ?? null) !== null;
        $edgeWebhookLastEventAt = is_array($edgeWebhookMeta) ? ($edgeWebhookMeta['last_event_at'] ?? null) : null;
        $edgeSpaFallback = (bool) (($edgeMeta['routing']['spa_fallback'] ?? null) ?? ($edgeMeta['spa_fallback'] ?? true));
        $edgeRuntimeMode = (string) ($edgeMeta['runtime_mode'] ?? 'static');
        $edgeOrigin = is_array($edgeMeta['origin'] ?? null) ? $edgeMeta['origin'] : null;
        $edgeAttachedDomains = is_array($edgeMeta['routing']['custom_domains'] ?? null) ? $edgeMeta['routing']['custom_domains'] : [];
        $edgeIsPreviewChild = ! empty($edgeMeta['preview_parent_site_id']);
        $edgeActiveDeploymentId = $edgeMeta['active_deployment_id'] ?? null;
        $edgeDeployments = $site->relationLoaded('edgeDeployments')
            ? $site->edgeDeployments
            : $site->edgeDeployments()->limit(20)->get();
        $edgeStatusBadgeClass = match ($site->status) {
            Site::STATUS_EDGE_ACTIVE => 'bg-emerald-100 text-emerald-800 ring-emerald-200/60 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-800/40',
            Site::STATUS_EDGE_PROVISIONING => 'bg-sky-100 text-sky-800 ring-sky-200/60 dark:bg-sky-950/40 dark:text-sky-300 dark:ring-sky-800/40',
            Site::STATUS_EDGE_FAILED => 'bg-rose-100 text-rose-800 ring-rose-200/60 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-800/40',
            default => 'bg-brand-sand/80 text-brand-moss ring-brand-ink/10',
        };
        $edgeStatusLabel = match ($site->status) {
            Site::STATUS_EDGE_ACTIVE => __('Live'),
            Site::STATUS_EDGE_PROVISIONING => __('Building'),
            Site::STATUS_EDGE_FAILED => __('Failed'),
            default => str_replace('_', ' ', (string) $site->status),
        };
        $edgeGithubRepoUrl = $edgeRepo !== '' ? 'https://github.com/'.$edgeRepo : null;
        $edgeLatestDeployment = $edgeDeployments->first();

        $edgeFakeMode = FakeEdgeProvision::enabled();
        $edgeUsesManagedBackend = $site->edge_backend === 'dply_edge';
        $edgeUsesByoCloudflare = $site->usesOrgCloudflareEdge();
        $edgePlatformReady = EdgePlatformCredentials::isProductionReady();
        $edgeWorkerZoneName = '';
        $edgeWorkerRoutes = [];
        $edgeWorkerScriptName = '';

        try {
            $deliveryContext = app(EdgeDeliveryContextResolver::class)->forSite($site);
            $edgeWorkerZoneName = $deliveryContext->workerZoneName;
            $edgeWorkerRoutes = $deliveryContext->workerRoutes;
            $edgeWorkerScriptName = $deliveryContext->workerScriptName;
        } catch (\Throwable) {
            // Org credential may be missing on incomplete BYO sites.
        }

        $edgeDeliveryBackendLabel = self::deliveryBackendLabel(
            $edgeUsesManagedBackend,
            $edgeUsesByoCloudflare,
            $edgeFakeMode,
        );
        $edgeDeliveryBanner = self::deliveryBanner(
            $edgeFakeMode,
            $edgeUsesManagedBackend,
            $edgeUsesByoCloudflare,
            $edgePlatformReady,
        );
        $edgeDeliveryHostname = $site->edgeHostname();

        return compact(
            'edgeMeta',
            'edgeSourceSpec',
            'edgeBuildSpec',
            'edgeLiveUrl',
            'edgeRepo',
            'edgeBranch',
            'edgeSourceRef',
            'edgeBuildCommand',
            'edgeOutputDir',
            'edgeDeployOnPush',
            'edgeWebhookMeta',
            'edgeGithubWebhookConnected',
            'edgeWebhookLastEventAt',
            'edgeSpaFallback',
            'edgeRuntimeMode',
            'edgeOrigin',
            'edgeAttachedDomains',
            'edgeIsPreviewChild',
            'edgeActiveDeploymentId',
            'edgeDeployments',
            'edgeStatusBadgeClass',
            'edgeStatusLabel',
            'edgeGithubRepoUrl',
            'edgeLatestDeployment',
            'edgeFakeMode',
            'edgeUsesManagedBackend',
            'edgeUsesByoCloudflare',
            'edgePlatformReady',
            'edgeWorkerZoneName',
            'edgeWorkerRoutes',
            'edgeWorkerScriptName',
            'edgeDeliveryBackendLabel',
            'edgeDeliveryBanner',
            'edgeDeliveryHostname',
        );
    }

    private static function deliveryBackendLabel(
        bool $usesManagedBackend,
        bool $usesByoCloudflare,
        bool $fakeMode,
    ): string {
        if ($usesByoCloudflare) {
            return __('Your Cloudflare account (Cloudflare Worker)');
        }

        if ($usesManagedBackend) {
            return $fakeMode
                ? __('Dply Edge (local fake backend)')
                : __('Dply Edge (Cloudflare Worker)');
        }

        return __('Unknown delivery backend');
    }

    /**
     * @return array{tone: string, title: string, message: string}|null
     */
    private static function deliveryBanner(
        bool $fakeMode,
        bool $usesManagedBackend,
        bool $usesByoCloudflare,
        bool $platformReady,
    ): ?array {
        if ($fakeMode) {
            $hint = EdgeLocalDevDiagnostics::fakeModeBannerHint();

            return [
                'tone' => 'amber',
                'title' => __('Fake edge — not Cloudflare Worker'),
                'message' => __('Local dev mode serves hostnames through this app. Deploys write to disk/cache only — not platform R2/KV. Set DPLY_FAKE_EDGE=false and redeploy for real Cloudflare delivery.')
                    .' '
                    .$hint['message'],
            ];
        }

        if ($usesManagedBackend && ! $platformReady) {
            return [
                'tone' => 'amber',
                'title' => __('Cloudflare platform not configured'),
                'message' => __('Platform Edge credentials are incomplete. Run php artisan dply:edge:bootstrap, deploy the worker, then set DPLY_FAKE_EDGE=false and redeploy this site.'),
            ];
        }

        if ($usesManagedBackend || $usesByoCloudflare) {
            return [
                'tone' => 'emerald',
                'title' => __('Live on Cloudflare Edge'),
                'message' => __('Builds publish to R2 and KV. Traffic is served by the dply Edge Worker — not this Laravel app.'),
            ];
        }

        return null;
    }
}
