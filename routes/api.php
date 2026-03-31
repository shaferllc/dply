<?php

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
    Route::middleware('fleet.operator')->group(function (): void {
        Route::get('/operator/summary', [OperatorSummaryController::class, 'show']);
        Route::get('/operator/readme', [OperatorReadmeController::class, 'show']);
    });

    $apiAbilities = config('api_token_permissions.http_route_abilities', []);

    Route::middleware(['auth.api', 'throttle:api'])->group(function () use ($apiAbilities): void {
        Route::get('/servers', [ServerController::class, 'index'])->middleware('ability:'.$apiAbilities['servers.index']);
        Route::post('/servers/{server}/deploy', [ServerController::class, 'deploy'])->middleware('ability:'.$apiAbilities['servers.deploy']);
        Route::post('/servers/{server}/run-command', [ServerController::class, 'runCommand'])->middleware('ability:'.$apiAbilities['servers.run_command']);

        Route::get('/servers/{server}/firewall', [ServerFirewallController::class, 'show'])->middleware('ability:'.$apiAbilities['firewall.show']);
        Route::get('/servers/{server}/firewall/preview', [ServerFirewallController::class, 'preview'])->middleware('ability:'.$apiAbilities['firewall.preview']);
        Route::get('/servers/{server}/firewall/drift', [ServerFirewallController::class, 'drift'])->middleware('ability:'.$apiAbilities['firewall.drift']);
        Route::get('/servers/{server}/firewall/terraform', [ServerFirewallController::class, 'terraform'])->middleware('ability:'.$apiAbilities['firewall.terraform']);
        Route::get('/servers/{server}/firewall/iptables', [ServerFirewallController::class, 'iptables'])->middleware('ability:'.$apiAbilities['firewall.iptables']);
        Route::post('/servers/{server}/firewall/apply', [ServerFirewallController::class, 'apply'])->middleware('ability:'.$apiAbilities['firewall.apply']);
        Route::get('/servers/{server}/firewall/export', [ServerFirewallController::class, 'export'])->middleware('ability:'.$apiAbilities['firewall.export']);
        Route::post('/servers/{server}/firewall/import', [ServerFirewallController::class, 'import'])->middleware('ability:'.$apiAbilities['firewall.import']);
        Route::post('/servers/{server}/firewall/bundled/{key}', [ServerFirewallController::class, 'applyBundled'])->middleware('ability:'.$apiAbilities['firewall.bundled_apply'])->where('key', '[a-z0-9_]+');
        Route::post('/servers/{server}/firewall/templates/{template}', [ServerFirewallController::class, 'applyTemplate'])->middleware('ability:'.$apiAbilities['firewall.template_apply']);
        Route::post('/servers/{server}/firewall/snapshots', [ServerFirewallController::class, 'createSnapshot'])->middleware('ability:'.$apiAbilities['firewall.snapshot_create']);
        Route::post('/servers/{server}/firewall/snapshots/{snapshot}/restore', [ServerFirewallController::class, 'restoreSnapshot'])->middleware('ability:'.$apiAbilities['firewall.snapshot_restore']);

        Route::get('/sites', [SiteController::class, 'index'])->middleware('ability:'.$apiAbilities['sites.index']);
        Route::post('/sites/{site}/deploy', [SiteController::class, 'deploy'])->middleware('ability:'.$apiAbilities['sites.deploy']);
        Route::get('/sites/{site}/deployments', [SiteController::class, 'deployments'])->middleware('ability:'.$apiAbilities['sites.deployments']);
        Route::get('/sites/{site}/deployments/{deployment}', [SiteController::class, 'showDeployment'])->middleware('ability:'.$apiAbilities['sites.deployment_show']);

        Route::get('/insights/summary', [InsightsController::class, 'organizationSummary'])->middleware('ability:'.$apiAbilities['insights.org_summary']);
        Route::get('/servers/{server}/insights', [InsightsController::class, 'serverFindings'])->middleware('ability:'.$apiAbilities['insights.server_findings']);
    });
});
