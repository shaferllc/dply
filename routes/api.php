<?php

use App\Http\Controllers\Api\AccountApiController;
use App\Http\Controllers\Api\Auth\DeviceAuthorizationController;
use App\Http\Controllers\Api\BillingApiController;
use App\Http\Controllers\Api\Edge\EdgeAccessApiController;
use App\Http\Controllers\Api\Edge\EdgeAliasApiController;
use App\Http\Controllers\Api\Edge\EdgeCacheApiController;
use App\Http\Controllers\Api\Edge\EdgeDeploymentApiController;
use App\Http\Controllers\Api\Edge\EdgeDomainApiController;
use App\Http\Controllers\Api\Edge\EdgeLintApiController;
use App\Http\Controllers\Api\Edge\EdgeLogApiController;
use App\Http\Controllers\Api\Edge\EdgePreviewApiController;
use App\Http\Controllers\Api\Edge\EdgeSiteApiController;
use App\Http\Controllers\Api\Edge\EdgeUsageApiController;
use App\Http\Controllers\Api\EdgeEnvController;
use App\Http\Controllers\Api\ImportMigrationController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\OperatorReadmeController;
use App\Http\Controllers\Api\OperatorSummaryController;
use App\Http\Controllers\Api\ProjectApiController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\ServerFirewallController;
use App\Http\Controllers\Api\ServerLogShippingController;
use App\Http\Controllers\Api\ServerSharedHostController;
use App\Http\Controllers\Api\ServerSystemUserApiController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SiteResourceApiController;
use App\Http\Controllers\Api\WorkerPoolJobEventController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Metrics API
|--------------------------------------------------------------------------
|
| POST /api/metrics accepts both:
| - guest server callbacks from server-metrics-snapshot.py using per-server token
| - ingest/export payloads using Bearer DPLY_METRICS_INGEST_TOKEN
|
*/
Route::post('/metrics', [MetricsController::class, 'store'])
    ->middleware(['throttle:metrics-guest-push', 'throttle:metrics-ingest']);

// Per-job Horizon events forwarded from worker pool boxes (Bearer = pool
// event_token), re-broadcast over Reverb to the org channel for the live
// worker-pool dashboard. High-frequency but tiny; throttled generously.
Route::post('/worker-pools/{pool}/job-events', [WorkerPoolJobEventController::class, 'store'])
    ->middleware('throttle:600,1');

Route::prefix('v1')->group(function (): void {
    // OAuth-style device-flow login for the dply CLI. The CLI calls
    // /auth/device/start (unauthenticated) to mint a code pair, points
    // the user at /auth/device on the web to approve, then polls
    // /auth/device/poll until the token is ready. Throttled tightly so
    // a runaway CLI loop can't hammer the DB.
    Route::post('/auth/device/start', [DeviceAuthorizationController::class, 'start'])
        ->middleware(['throttle:30,1']);
    Route::post('/auth/device/poll', [DeviceAuthorizationController::class, 'poll'])
        ->middleware(['throttle:60,1']);

    Route::middleware('fleet.operator')->group(function (): void {
        Route::get('/operator/summary', [OperatorSummaryController::class, 'show']);
        Route::get('/operator/readme', [OperatorReadmeController::class, 'show']);
    });

    $apiAbilities = config('api_token_permissions.http_route_abilities', []);

    Route::middleware(['auth.api', 'throttle:api'])->group(function () use ($apiAbilities): void {
        Route::get('/account', [AccountApiController::class, 'show'])
            ->middleware('ability:'.$apiAbilities['account.show']);
        Route::get('/account/organizations', [AccountApiController::class, 'organizations'])
            ->middleware('ability:'.$apiAbilities['account.organizations']);
        Route::get('/account/projects', [AccountApiController::class, 'projects'])
            ->middleware('ability:'.$apiAbilities['account.projects']);
        Route::get('/account/sessions', [AccountApiController::class, 'sessions'])
            ->middleware('ability:'.$apiAbilities['account.sessions']);
        Route::delete('/account/sessions/{apiToken}', [AccountApiController::class, 'destroySession'])
            ->middleware('ability:'.$apiAbilities['account.sessions_destroy']);

        Route::get('/billing', [BillingApiController::class, 'show'])
            ->middleware('ability:'.$apiAbilities['billing.show']);
        Route::get('/billing/breakdown', [BillingApiController::class, 'breakdown'])
            ->middleware('ability:'.$apiAbilities['billing.breakdown']);
        Route::get('/billing/invoices', [BillingApiController::class, 'invoices'])
            ->middleware('ability:'.$apiAbilities['billing.invoices']);

        Route::get('/projects', [ProjectApiController::class, 'index'])
            ->middleware('ability:'.$apiAbilities['projects.index']);
        Route::post('/projects', [ProjectApiController::class, 'store'])
            ->middleware('ability:'.$apiAbilities['projects.store']);
        Route::get('/projects/{project}', [ProjectApiController::class, 'show'])
            ->middleware('ability:'.$apiAbilities['projects.show']);
        Route::patch('/projects/{project}', [ProjectApiController::class, 'update'])
            ->middleware('ability:'.$apiAbilities['projects.update']);
        Route::delete('/projects/{project}', [ProjectApiController::class, 'destroy'])
            ->middleware('ability:'.$apiAbilities['projects.destroy']);
        Route::get('/projects/{project}/health', [ProjectApiController::class, 'health'])
            ->middleware('ability:'.$apiAbilities['projects.health']);
        Route::get('/projects/{project}/members', [ProjectApiController::class, 'members'])
            ->middleware('ability:'.$apiAbilities['projects.members_index']);
        Route::post('/projects/{project}/members', [ProjectApiController::class, 'storeMember'])
            ->middleware('ability:'.$apiAbilities['projects.members_store']);
        Route::delete('/projects/{project}/members/{member}', [ProjectApiController::class, 'destroyMember'])
            ->middleware('ability:'.$apiAbilities['projects.members_destroy']);
        Route::post('/projects/{project}/servers/{server}/attach', [ProjectApiController::class, 'attachServer'])
            ->middleware('ability:'.$apiAbilities['projects.servers_attach']);
        Route::delete('/projects/{project}/servers/{server}/detach', [ProjectApiController::class, 'detachServer'])
            ->middleware('ability:'.$apiAbilities['projects.servers_detach']);
        Route::post('/projects/{project}/sites/{site}/attach', [ProjectApiController::class, 'attachSite'])
            ->middleware('ability:'.$apiAbilities['projects.sites_attach']);
        Route::delete('/projects/{project}/sites/{site}/detach', [ProjectApiController::class, 'detachSite'])
            ->middleware('ability:'.$apiAbilities['projects.sites_detach']);
        Route::get('/projects/{project}/deploys', [ProjectApiController::class, 'deploys'])
            ->middleware('ability:'.$apiAbilities['projects.deploys_index']);
        Route::post('/projects/{project}/deploy', [ProjectApiController::class, 'deploy'])
            ->middleware('ability:'.$apiAbilities['projects.deploy']);
        Route::get('/projects/{project}/deploys/{deployRun}', [ProjectApiController::class, 'showDeploy'])
            ->middleware('ability:'.$apiAbilities['projects.deploys_show']);
        Route::get('/projects/{project}/environments', [ProjectApiController::class, 'environments'])
            ->middleware('ability:'.$apiAbilities['projects.environments_index']);
        Route::post('/projects/{project}/environments', [ProjectApiController::class, 'storeEnvironment'])
            ->middleware('ability:'.$apiAbilities['projects.environments_store']);
        Route::delete('/projects/{project}/environments/{environment}', [ProjectApiController::class, 'destroyEnvironment'])
            ->middleware('ability:'.$apiAbilities['projects.environments_destroy']);
        Route::get('/projects/{project}/variables', [ProjectApiController::class, 'variables'])
            ->middleware('ability:'.$apiAbilities['projects.variables_index']);
        Route::put('/projects/{project}/variables', [ProjectApiController::class, 'upsertVariable'])
            ->middleware('ability:'.$apiAbilities['projects.variables_upsert']);
        Route::delete('/projects/{project}/variables/{variable}', [ProjectApiController::class, 'destroyVariable'])
            ->middleware('ability:'.$apiAbilities['projects.variables_destroy']);
        Route::get('/projects/{project}/runbooks', [ProjectApiController::class, 'runbooks'])
            ->middleware('ability:'.$apiAbilities['projects.runbooks_index']);
        Route::post('/projects/{project}/runbooks', [ProjectApiController::class, 'storeRunbook'])
            ->middleware('ability:'.$apiAbilities['projects.runbooks_store']);
        Route::delete('/projects/{project}/runbooks/{runbook}', [ProjectApiController::class, 'destroyRunbook'])
            ->middleware('ability:'.$apiAbilities['projects.runbooks_destroy']);

        Route::get('/servers', [ServerController::class, 'index'])->middleware('ability:'.$apiAbilities['servers.index']);
        Route::post('/servers/{server}/run-command', [ServerController::class, 'runCommand'])->middleware('ability:'.$apiAbilities['servers.run_command']);
        Route::get('/servers/{server}/shared-host/explain', [ServerSharedHostController::class, 'explain'])
            ->middleware('ability:'.$apiAbilities['servers.index']);

        Route::get('/servers/{server}/system-users', [ServerSystemUserApiController::class, 'index'])
            ->middleware('ability:'.$apiAbilities['servers.system_users.index']);
        Route::post('/servers/{server}/system-users/sync', [ServerSystemUserApiController::class, 'sync'])
            ->middleware('ability:'.$apiAbilities['servers.system_users.sync']);
        Route::post('/servers/{server}/system-users', [ServerSystemUserApiController::class, 'store'])
            ->middleware('ability:'.$apiAbilities['servers.system_users.store']);
        Route::patch('/servers/{server}/system-users/{username}', [ServerSystemUserApiController::class, 'update'])
            ->middleware('ability:'.$apiAbilities['servers.system_users.update'])
            ->where('username', '[a-zA-Z0-9._-]+');
        Route::delete('/servers/{server}/system-users/{username}', [ServerSystemUserApiController::class, 'destroy'])
            ->middleware('ability:'.$apiAbilities['servers.system_users.destroy'])
            ->where('username', '[a-zA-Z0-9._-]+');

        Route::get('/servers/{server}/log-shipping', [ServerLogShippingController::class, 'show'])
            ->middleware('ability:'.$apiAbilities['servers.log_shipping.show']);
        Route::post('/servers/{server}/log-shipping/enable', [ServerLogShippingController::class, 'enable'])
            ->middleware('ability:'.$apiAbilities['servers.log_shipping.enable']);
        Route::post('/servers/{server}/log-shipping/resync', [ServerLogShippingController::class, 'resync'])
            ->middleware('ability:'.$apiAbilities['servers.log_shipping.resync']);
        Route::delete('/servers/{server}/log-shipping', [ServerLogShippingController::class, 'disable'])
            ->middleware('ability:'.$apiAbilities['servers.log_shipping.disable']);

        Route::get('/servers/{server}/firewall', [ServerFirewallController::class, 'show'])->middleware('ability:'.$apiAbilities['firewall.show']);
        Route::post('/servers/{server}/firewall/apply', [ServerFirewallController::class, 'apply'])->middleware('ability:'.$apiAbilities['firewall.apply']);
        Route::post('/servers/{server}/firewall/bundled/{key}', [ServerFirewallController::class, 'applyBundled'])->middleware('ability:'.$apiAbilities['firewall.bundled_apply'])->where('key', '[a-z0-9_]+');
        Route::post('/servers/{server}/firewall/templates/{template}', [ServerFirewallController::class, 'applyTemplate'])->middleware('ability:'.$apiAbilities['firewall.template_apply']);

        Route::get('/sites', [SiteController::class, 'index'])->middleware('ability:'.$apiAbilities['sites.index']);
        Route::post('/sites/{site}/deploy', [SiteController::class, 'deploy'])->middleware('ability:'.$apiAbilities['sites.deploy']);
        Route::get('/sites/{site}/deployments', [SiteController::class, 'deployments'])->middleware('ability:'.$apiAbilities['sites.deployments']);
        Route::get('/sites/{site}/deployments/{deployment}', [SiteController::class, 'showDeployment'])->middleware('ability:'.$apiAbilities['sites.deployment_show']);

        // Extended site resource endpoints (slug-routed via Site::getRouteKeyName)
        Route::get('/sites/{site}', [SiteResourceApiController::class, 'show'])->middleware('ability:'.$apiAbilities['sites.show']);
        Route::patch('/sites/{site}', [SiteResourceApiController::class, 'update'])->middleware('ability:'.$apiAbilities['sites.update']);
        Route::get('/sites/{site}/workers', [SiteResourceApiController::class, 'workers'])->middleware('ability:'.$apiAbilities['sites.workers']);
        Route::get('/sites/{site}/schedules', [SiteResourceApiController::class, 'schedules'])->middleware('ability:'.$apiAbilities['sites.schedules']);
        Route::get('/sites/{site}/errors', [SiteResourceApiController::class, 'errors'])->middleware('ability:'.$apiAbilities['sites.errors']);
        Route::get('/sites/{site}/uptime', [SiteResourceApiController::class, 'uptime'])->middleware('ability:'.$apiAbilities['sites.uptime']);
        Route::get('/sites/{site}/basic-auth', [SiteResourceApiController::class, 'basicAuth'])->middleware('ability:'.$apiAbilities['sites.basic_auth']);
        Route::post('/sites/{site}/basic-auth', [SiteResourceApiController::class, 'addBasicAuth'])->middleware('ability:'.$apiAbilities['sites.basic_auth_write']);
        Route::delete('/sites/{site}/basic-auth/{username}', [SiteResourceApiController::class, 'removeBasicAuth'])->middleware('ability:'.$apiAbilities['sites.basic_auth_write'])->where('username', '[a-zA-Z0-9._-]+');
        Route::get('/sites/{site}/ssl', [SiteResourceApiController::class, 'ssl'])->middleware('ability:'.$apiAbilities['sites.ssl']);
        Route::get('/sites/{site}/domains', [SiteResourceApiController::class, 'domains'])->middleware('ability:'.$apiAbilities['sites.domains']);
        Route::post('/sites/{site}/domains', [SiteResourceApiController::class, 'addDomain'])->middleware('ability:'.$apiAbilities['sites.domains_write']);
        Route::delete('/sites/{site}/domains/{hostname}', [SiteResourceApiController::class, 'removeDomain'])->middleware('ability:'.$apiAbilities['sites.domains_write'])->where('hostname', '[A-Za-z0-9.-]+');
        Route::get('/sites/{site}/databases', [SiteResourceApiController::class, 'databases'])->middleware('ability:'.$apiAbilities['sites.databases']);
        Route::get('/sites/{site}/commits', [SiteResourceApiController::class, 'commits'])->middleware('ability:'.$apiAbilities['sites.commits']);
        Route::get('/sites/{site}/system-user', [SiteResourceApiController::class, 'systemUser'])->middleware('ability:'.$apiAbilities['sites.system_user']);

        Route::get('/insights/summary', [InsightsController::class, 'organizationSummary'])->middleware('ability:'.$apiAbilities['insights.org_summary']);
        Route::get('/servers/{server}/insights', [InsightsController::class, 'serverFindings'])->middleware('ability:'.$apiAbilities['insights.server_findings']);

        Route::get('/imports/migrations', [ImportMigrationController::class, 'index'])->middleware('ability:'.$apiAbilities['imports.migrations_index']);
        Route::get('/imports/migrations/{migration}', [ImportMigrationController::class, 'show'])->middleware('ability:'.$apiAbilities['imports.migrations_show']);

        // Edge surface runs under a higher per-token throttle than
        // the rest of v1 (log tail + CI deploys are chatty by design).
        // See edge-api RateLimiter in AppServiceProvider.
        Route::prefix('edge')->middleware('throttle:edge-api')->group(function () use ($apiAbilities): void {
            Route::get('/sites', [EdgeSiteApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.sites.index']);
            Route::get('/sites/{site}', [EdgeSiteApiController::class, 'show'])
                ->middleware('ability:'.$apiAbilities['edge.sites.show']);

            Route::get('/sites/{site}/deployments', [EdgeDeploymentApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.deployments.index']);
            Route::post('/sites/{site}/deployments', [EdgeDeploymentApiController::class, 'store'])
                ->middleware('ability:'.$apiAbilities['edge.deployments.store']);
            Route::get('/sites/{site}/deployments/{deployment}', [EdgeDeploymentApiController::class, 'show'])
                ->middleware('ability:'.$apiAbilities['edge.deployments.show']);
            Route::post('/sites/{site}/deployments/{deployment}/rollback', [EdgeDeploymentApiController::class, 'rollback'])
                ->middleware('ability:'.$apiAbilities['edge.deployments.rollback']);

            Route::get('/sites/{site}/previews', [EdgePreviewApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.previews.index']);
            Route::post('/sites/{site}/previews', [EdgePreviewApiController::class, 'store'])
                ->middleware('ability:'.$apiAbilities['edge.previews.store']);
            Route::delete('/sites/{site}/previews/{preview}', [EdgePreviewApiController::class, 'destroy'])
                ->middleware('ability:'.$apiAbilities['edge.previews.destroy']);
            Route::post('/sites/{site}/previews/{preview}/promote', [EdgePreviewApiController::class, 'promote'])
                ->middleware('ability:'.$apiAbilities['edge.previews.promote']);

            Route::get('/sites/{site}/domains', [EdgeDomainApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.domains.index']);
            Route::post('/sites/{site}/domains', [EdgeDomainApiController::class, 'store'])
                ->middleware('ability:'.$apiAbilities['edge.domains.store']);
            Route::post('/sites/{site}/domains/{hostname}/verify', [EdgeDomainApiController::class, 'verify'])
                ->middleware('ability:'.$apiAbilities['edge.domains.verify'])
                ->where('hostname', '[A-Za-z0-9.-]+');
            Route::delete('/sites/{site}/domains/{hostname}', [EdgeDomainApiController::class, 'destroy'])
                ->middleware('ability:'.$apiAbilities['edge.domains.destroy'])
                ->where('hostname', '[A-Za-z0-9.-]+');

            Route::get('/sites/{site}/aliases', [EdgeAliasApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.aliases.index']);

            Route::get('/sites/{site}/access', [EdgeAccessApiController::class, 'show'])
                ->middleware('ability:'.$apiAbilities['edge.access.show']);
            Route::patch('/sites/{site}/access', [EdgeAccessApiController::class, 'update'])
                ->middleware('ability:'.$apiAbilities['edge.access.update']);

            Route::post('/sites/{site}/cache/purge', [EdgeCacheApiController::class, 'purge'])
                ->middleware('ability:'.$apiAbilities['edge.cache.purge']);

            Route::get('/sites/{site}/usage', [EdgeUsageApiController::class, 'show'])
                ->middleware('ability:'.$apiAbilities['edge.usage.show']);

            Route::get('/sites/{site}/logs', [EdgeLogApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.logs.index']);

            Route::post('/lint', [EdgeLintApiController::class, 'store'])
                ->middleware('ability:'.$apiAbilities['edge.lint.store']);

            // P-env: per-site environment variables. Values are
            // encrypted at rest + never returned by GET — list shows
            // keys + updated_at only.
            Route::get('/sites/{site}/env', [EdgeEnvController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.env.index']);
            Route::put('/sites/{site}/env', [EdgeEnvController::class, 'bulkUpdate'])
                ->middleware('ability:'.$apiAbilities['edge.env.update']);
            Route::patch('/sites/{site}/env/{key}', [EdgeEnvController::class, 'upsert'])
                ->middleware('ability:'.$apiAbilities['edge.env.upsert'])
                ->where('key', '[A-Z][A-Z0-9_]{0,127}');
            Route::delete('/sites/{site}/env/{key}', [EdgeEnvController::class, 'destroy'])
                ->middleware('ability:'.$apiAbilities['edge.env.destroy'])
                ->where('key', '[A-Z][A-Z0-9_]{0,127}');
        });
    });
});
