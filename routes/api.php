<?php

use App\Http\Controllers\Api\AccountApiController;
use App\Http\Controllers\Api\Auth\DeviceAuthorizationController;
use App\Http\Controllers\Api\BundleEntitlementsController;
use App\Http\Controllers\Api\CapabilitiesApiController;
use App\Http\Controllers\Api\ImportMigrationController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Http\Controllers\Api\OperatorReadmeController;
use App\Http\Controllers\Api\OperatorSummaryController;
use App\Http\Controllers\Api\ProjectApiController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\ServerFirewallController;
use App\Http\Controllers\Api\ServerLogShippingController;
use App\Http\Controllers\Api\ServerMonitoringController;
use App\Http\Controllers\Api\ServerSharedHostController;
use App\Http\Controllers\Api\ServerSystemUserApiController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SiteEnvApiController;
use App\Http\Controllers\Api\SiteQueueEventController;
use App\Http\Controllers\Api\SiteResourceApiController;
use App\Http\Controllers\Api\VmSiteCreateApiController;
use App\Http\Controllers\Api\WorkerPoolJobEventController;
use App\Modules\Billing\Http\Controllers\Api\BillingApiController;
use App\Modules\Cache\Http\Controllers\DynamoDbCompatibilityController;
use App\Modules\Queue\Http\Controllers\QueueFailedJobController;
use App\Modules\Queue\Http\Controllers\SqsCompatibilityController;
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
// The in-app queue agent reports here. Per-site bearer token; no session, no
// CSRF — it is called from a customer's worker process, not a browser.
Route::post('/sites/{site}/queue-events', [SiteQueueEventController::class, 'store'])
    ->middleware('throttle:600,1')
    ->name('sites.queue-events');
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

    // Bundled-products entitlement pull (reconcile backstop) — service-token auth,
    // called by tracely/Lookout. Dark until BUNDLE_ENTITLEMENTS_API_TOKEN is set.
    Route::middleware('bundle.service')->group(function (): void {
        Route::get('/orgs/{organization}/entitlements', [BundleEntitlementsController::class, 'show'])
            ->middleware('throttle:120,1');
    });

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

        // What this instance offers — surfaces, creatable kinds, and upload
        // limits. `dply init` reads it before showing anything, so the CLI
        // hardcodes none of it and an older instance (404 here) degrades to
        // a named message instead of a mystery.
        Route::get('/capabilities', [CapabilitiesApiController::class, 'show'])
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

        // Metrics agent state + samples, and the three operations the Metrics
        // workspace starts. A consumer control plane (the local production-data
        // mirror) renders that page from `show` and posts here instead of
        // dispatching SSH jobs for a host whose key it does not hold.
        Route::get('/servers/{server}/metrics', [ServerMonitoringController::class, 'show'])
            ->middleware('ability:'.$apiAbilities['servers.metrics.show']);
        Route::post('/servers/{server}/metrics/probe', [ServerMonitoringController::class, 'probe'])
            ->middleware('ability:'.$apiAbilities['servers.metrics.probe']);
        Route::post('/servers/{server}/metrics/install', [ServerMonitoringController::class, 'install'])
            ->middleware('ability:'.$apiAbilities['servers.metrics.install']);
        Route::patch('/servers/{server}/metrics/thresholds', [ServerMonitoringController::class, 'thresholds'])
            ->middleware('ability:'.$apiAbilities['servers.metrics.thresholds']);

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
        // Creating a site on a server the org owns. Ordinary webserver hosts
        // only — a container/Kubernetes/headless host returns a typed
        // blocker pointing at the dashboard, where its host-specific options live.
        Route::post('/servers/{server}/sites', [VmSiteCreateApiController::class, 'store'])
            ->middleware(['ability:'.$apiAbilities['sites.store'], 'throttle:site-create']);

        Route::post('/sites/{site}/deploy', [SiteController::class, 'deploy'])->middleware('ability:'.$apiAbilities['sites.deploy']);
        Route::get('/sites/{site}/deployments', [SiteController::class, 'deployments'])->middleware('ability:'.$apiAbilities['sites.deployments']);
        Route::get('/sites/{site}/deployments/{deployment}', [SiteController::class, 'showDeployment'])->middleware('ability:'.$apiAbilities['sites.deployment_show']);

        // Extended site resource endpoints (slug-routed via Site::getRouteKeyName)
        Route::get('/sites/{site}', [SiteResourceApiController::class, 'show'])->middleware('ability:'.$apiAbilities['sites.show']);
        Route::patch('/sites/{site}', [SiteResourceApiController::class, 'update'])->middleware('ability:'.$apiAbilities['sites.update']);

        // VM/BYO site env vars (values encrypted at rest, never returned by GET).
        Route::get('/sites/{site}/env', [SiteEnvApiController::class, 'index'])->middleware('ability:'.$apiAbilities['sites.env.index']);
        Route::get('/sites/{site}/env/content', [SiteEnvApiController::class, 'showContent'])
            ->middleware('ability:'.$apiAbilities['sites.env.content']);
        Route::put('/sites/{site}/env/content', [SiteEnvApiController::class, 'updateContent'])
            ->middleware('ability:'.$apiAbilities['sites.env.content_put']);
        Route::patch('/sites/{site}/env/{key}', [SiteEnvApiController::class, 'upsert'])
            ->middleware('ability:'.$apiAbilities['sites.env.set'])->where('key', '[A-Za-z_][A-Za-z0-9_]{0,127}');
        Route::delete('/sites/{site}/env/{key}', [SiteEnvApiController::class, 'destroy'])
            ->middleware('ability:'.$apiAbilities['sites.env.delete'])->where('key', '[A-Za-z_][A-Za-z0-9_]{0,127}');
        Route::get('/sites/{site}/workers', [SiteResourceApiController::class, 'workers'])->middleware('ability:'.$apiAbilities['sites.workers']);
        Route::get('/sites/{site}/schedules', [SiteResourceApiController::class, 'schedules'])->middleware('ability:'.$apiAbilities['sites.schedules']);
        Route::get('/sites/{site}/errors', [SiteResourceApiController::class, 'errors'])->middleware('ability:'.$apiAbilities['sites.errors']);
        // Acting on an error, not just reading it: `dply errors dismiss|retry|fix`.
        Route::post('/sites/{site}/errors/dismiss', [SiteResourceApiController::class, 'dismissErrors'])->middleware('ability:'.$apiAbilities['sites.errors_dismiss']);
        Route::post('/sites/{site}/errors/{event}/retry', [SiteResourceApiController::class, 'retryError'])->middleware('ability:'.$apiAbilities['sites.errors_retry']);
        Route::post('/sites/{site}/errors/{event}/remediate', [SiteResourceApiController::class, 'remediateError'])->middleware('ability:'.$apiAbilities['sites.errors_remediate']);
        // Notification routing — channels are org-level, subscriptions hang off
        // a site or a server. Same matrix the workspace tabs write.
        Route::get('/notifications/channels', [NotificationApiController::class, 'channels'])->middleware('ability:'.$apiAbilities['notifications.channels']);
        Route::get('/notifications/events', [NotificationApiController::class, 'events'])->middleware('ability:'.$apiAbilities['notifications.events']);
        Route::post('/notifications/channels/{channel}/test', [NotificationApiController::class, 'test'])->middleware('ability:'.$apiAbilities['notifications.test']);
        Route::get('/sites/{site}/notifications', [NotificationApiController::class, 'siteIndex'])->middleware('ability:'.$apiAbilities['notifications.site_index']);
        Route::post('/sites/{site}/notifications', [NotificationApiController::class, 'siteUpdate'])->middleware('ability:'.$apiAbilities['notifications.site_update']);
        Route::get('/servers/{server}/notifications', [NotificationApiController::class, 'serverIndex'])->middleware('ability:'.$apiAbilities['notifications.server_index']);
        Route::post('/servers/{server}/notifications', [NotificationApiController::class, 'serverUpdate'])->middleware('ability:'.$apiAbilities['notifications.server_update']);

        Route::get('/sites/{site}/uptime', [SiteResourceApiController::class, 'uptime'])->middleware('ability:'.$apiAbilities['sites.uptime']);
        Route::get('/sites/{site}/uptime/history', [SiteResourceApiController::class, 'uptimeHistory'])->middleware('ability:'.$apiAbilities['sites.uptime_history']);
        Route::post('/sites/{site}/uptime/check', [SiteResourceApiController::class, 'uptimeCheck'])->middleware('ability:'.$apiAbilities['sites.uptime_check']);
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

        // php artisan on a site, over the RemoteCli engine (risk gate + audit
        // trail). Non-instant commands queue and are polled by run id.
        Route::post('/sites/{site}/artisan', [SiteResourceApiController::class, 'runArtisan'])->middleware('ability:'.$apiAbilities['sites.artisan']);
        Route::get('/sites/{site}/artisan/runs/{run}', [SiteResourceApiController::class, 'artisanRun'])->middleware('ability:'.$apiAbilities['sites.artisan_run'])->where('run', '[0-9]+');

        Route::get('/insights/summary', [InsightsController::class, 'organizationSummary'])->middleware('ability:'.$apiAbilities['insights.org_summary']);
        Route::get('/servers/{server}/insights', [InsightsController::class, 'serverFindings'])->middleware('ability:'.$apiAbilities['insights.server_findings']);

        Route::get('/imports/migrations', [ImportMigrationController::class, 'index'])->middleware('ability:'.$apiAbilities['imports.migrations_index']);
        Route::get('/imports/migrations/{migration}', [ImportMigrationController::class, 'show'])->middleware('ability:'.$apiAbilities['imports.migrations_show']);

    });
});

/*
|--------------------------------------------------------------------------
| dply Queue data plane
|--------------------------------------------------------------------------
|
| The SQS-compatible surface. Sits OUTSIDE the /v1 group on purpose:
|
|   - Auth is SigV4 with a per-namespace credential, not an ApiToken bearer.
|     `auth.api` resolves a User, bcrypt-checks, and writes last_used_at on
|     every call — all wrong for a credential presented hundreds of times a
|     minute from inside a container.
|   - `throttle:api` is 60/min. One polling queue worker would exhaust that in
|     seconds, so this uses its own entitlement-driven, namespace-keyed limiter.
|
| One route for every action: the AWS JSON protocol dispatches on the
| X-Amz-Target header, not the path, so the SDK posts everything to the queue
| URL itself.
|
| CSRF exemption comes from App\Support\MachineCallbackPaths (`api/queue/*`),
| the same canonical list the guest gates read.
|
*/
Route::prefix('queue/v1')
    ->middleware(['auth.queue', 'throttle:dply-queue'])
    ->group(function (): void {
        // dply-native endpoints. Registered BEFORE the SQS catch-all, which
        // would otherwise swallow `/failed-jobs` as a queue name.
        //
        // These are not SQS operations, so they take a bearer token rather
        // than a SigV4 signature — there is no compatibility contract to
        // honour, and requiring a signature would mean shipping a signer into
        // every customer app.
        //
        // The `/locks/*` routes that used to lead this group are gone: locks
        // are a cache concern and now come from dply Cache's DynamoDB-
        // compatible endpoint through the framework's own `DynamoDbLock`, with
        // no injected client code. See docs/adr/dply-cache.md, decision 8.

        // Failed jobs. Backs a FailedJobProviderInterface in the app, so a job
        // that exhausts its attempts is recorded here instead of in a
        // per-container SQLite file that vanishes with the container.
        Route::get('/failed-jobs', [QueueFailedJobController::class, 'index'])->name('queue.failed.index');
        Route::post('/failed-jobs', [QueueFailedJobController::class, 'store'])->name('queue.failed.store');
        Route::post('/failed-jobs/flush', [QueueFailedJobController::class, 'flush'])->name('queue.failed.flush');
        Route::get('/failed-jobs/{id}', [QueueFailedJobController::class, 'show'])->name('queue.failed.show');
        Route::delete('/failed-jobs/{id}', [QueueFailedJobController::class, 'destroy'])->name('queue.failed.destroy');

        Route::post('/{queue?}', SqsCompatibilityController::class)
            ->where('queue', '[A-Za-z0-9._-]{1,128}')
            ->name('queue.sqs');
    });

/*
|--------------------------------------------------------------------------
| dply Cache data plane
|--------------------------------------------------------------------------
|
| The DynamoDB-compatible surface. Sits OUTSIDE the /v1 group for the same
| reasons the queue's does: auth is SigV4 with a service credential rather than
| an ApiToken bearer, and `throttle:api` at 60/min would be exhausted by a
| single page doing twenty cache reads.
|
| ONE route, no path parameter — unlike the queue, which lets a client address
| a queue by URL. The AWS JSON protocol dispatches on the X-Amz-Target header
| and names the table in the body, so a path segment here would be a second,
| unauthenticated way to say which cache a request is for. There is exactly one
| tenancy input and the grant map decides it (docs/adr/dply-cache.md,
| decision 14).
|
| CSRF exemption comes from App\Support\MachineCallbackPaths (`api/cache/*`).
|
*/
Route::prefix('cache/v1')
    ->middleware(['auth.cache', 'throttle:dply-cache'])
    ->group(function (): void {
        Route::post('/', DynamoDbCompatibilityController::class)->name('cache.dynamodb');
    });
