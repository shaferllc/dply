<?php

use App\Http\Controllers\Api\Auth\DeviceAuthorizationController;
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
use App\Http\Controllers\Api\ImportMigrationController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\OperatorReadmeController;
use App\Http\Controllers\Api\OperatorSummaryController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\ServerFirewallController;
use App\Http\Controllers\Api\SiteController;
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
        Route::get('/servers', [ServerController::class, 'index'])->middleware('ability:'.$apiAbilities['servers.index']);
        // POST /servers/{id}/deploy was removed when the deploy_command
        // column was dropped — there's no field for it to write to or
        // execute against. /run-command stays for ad-hoc remote execution.
        Route::post('/servers/{server}/run-command', [ServerController::class, 'runCommand'])->middleware('ability:'.$apiAbilities['servers.run_command']);

        Route::get('/servers/{server}/firewall', [ServerFirewallController::class, 'show'])->middleware('ability:'.$apiAbilities['firewall.show']);
        Route::post('/servers/{server}/firewall/apply', [ServerFirewallController::class, 'apply'])->middleware('ability:'.$apiAbilities['firewall.apply']);
        Route::post('/servers/{server}/firewall/bundled/{key}', [ServerFirewallController::class, 'applyBundled'])->middleware('ability:'.$apiAbilities['firewall.bundled_apply'])->where('key', '[a-z0-9_]+');
        Route::post('/servers/{server}/firewall/templates/{template}', [ServerFirewallController::class, 'applyTemplate'])->middleware('ability:'.$apiAbilities['firewall.template_apply']);

        Route::get('/sites', [SiteController::class, 'index'])->middleware('ability:'.$apiAbilities['sites.index']);
        Route::post('/sites/{site}/deploy', [SiteController::class, 'deploy'])->middleware('ability:'.$apiAbilities['sites.deploy']);
        Route::get('/sites/{site}/deployments', [SiteController::class, 'deployments'])->middleware('ability:'.$apiAbilities['sites.deployments']);
        Route::get('/sites/{site}/deployments/{deployment}', [SiteController::class, 'showDeployment'])->middleware('ability:'.$apiAbilities['sites.deployment_show']);

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
            Route::get('/sites/{site}/env', [\App\Http\Controllers\Api\EdgeEnvController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.env.index']);
            Route::put('/sites/{site}/env', [\App\Http\Controllers\Api\EdgeEnvController::class, 'bulkUpdate'])
                ->middleware('ability:'.$apiAbilities['edge.env.update']);
            Route::patch('/sites/{site}/env/{key}', [\App\Http\Controllers\Api\EdgeEnvController::class, 'upsert'])
                ->middleware('ability:'.$apiAbilities['edge.env.upsert'])
                ->where('key', '[A-Z][A-Z0-9_]{0,127}');
            Route::delete('/sites/{site}/env/{key}', [\App\Http\Controllers\Api\EdgeEnvController::class, 'destroy'])
                ->middleware('ability:'.$apiAbilities['edge.env.destroy'])
                ->where('key', '[A-Z][A-Z0-9_]{0,127}');
        });
    });
});
