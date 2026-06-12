<?php

use App\Http\Controllers\CaddyAdminApiProxyController;
use App\Http\Controllers\CancelServerProvisionController;
use App\Http\Controllers\CliInstallController;
use App\Http\Controllers\CloudDeployWebhookController;
use App\Http\Controllers\Credentials\ProviderOAuthController;
use App\Http\Controllers\DatabaseCredentialShareController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\Edge\EdgeAuditLogExportController;
use App\Http\Controllers\Edge\EdgeLogCsvDownloadController;
use App\Http\Controllers\Edge\EdgeRepoConfigYamlDownloadController;
use App\Http\Controllers\EdgeDeployHookController;
use App\Http\Controllers\EdgeLogIngestController;
use App\Http\Controllers\EdgeLogpushIngestController;
use App\Http\Controllers\EdgePreviewAccessController;
use App\Http\Controllers\EdgePreviewCommentsController;
use App\Http\Controllers\EdgeVitalsIngestController;
use App\Http\Controllers\EnvoyAdminProxyController;
use App\Http\Controllers\FunctionLogIngestController;
use App\Http\Controllers\GithubCloudWebhookController;
use App\Http\Controllers\GithubEdgeWebhookController;
use App\Http\Controllers\LogViewerShareController;
use App\Http\Controllers\OrganizationComplianceExportController;
use App\Http\Controllers\QuickDownloadController;
use App\Http\Controllers\ServerCredentialShareController;
use App\Http\Controllers\ServerlessFunctionProxyController;
use App\Http\Controllers\Servers\ServerWorkspaceFileDownloadController;
use App\Http\Controllers\SiteDeployWebhookController;
use App\Http\Controllers\Sites\SiteFileDownloadController;
use App\Http\Controllers\SiteScheduleController;
use App\Http\Controllers\SiteWorkspaceController;
use App\Http\Controllers\TraefikDashboardProxyController;
use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Jobs\RunSetupScriptJob;
use App\Livewire\Admin\AuditLog as AdminAuditLog;
use App\Livewire\Admin\BetaInvites as AdminBetaInvites;
use App\Livewire\Admin\ComingSoonAccess as AdminComingSoonAccess;
use App\Livewire\Admin\Flags\GlobalFlags as AdminGlobalFlags;
use App\Livewire\Admin\Flags\ProductLineFlags as AdminProductLineFlags;
use App\Livewire\Admin\Operations as AdminOperations;
use App\Livewire\Admin\Organizations\Index as AdminOrganizationsIndex;
use App\Livewire\Admin\Organizations\Show as AdminOrganizationsShow;
use App\Livewire\Admin\Overview as AdminOverview;
use App\Livewire\Admin\Roadmap\Index as AdminRoadmapIndex;
use App\Livewire\Auth\DeviceApproval as AuthDeviceApproval;
use App\Livewire\Backups\Databases as BackupsDatabases;
use App\Livewire\Backups\Files as BackupsFiles;
use App\Livewire\Billing\Analytics as BillingAnalytics;
use App\Livewire\Billing\Invoices as BillingInvoices;
use App\Livewire\Billing\Show as BillingShow;
use App\Livewire\Cloud\Create as CloudCreate;
use App\Livewire\Cloud\DatabaseCreate as CloudDatabaseCreate;
use App\Livewire\Cloud\DatabaseIndex as CloudDatabaseIndex;
use App\Livewire\Cloud\DeployDetail;
use App\Livewire\Cloud\Index as CloudIndex;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Edge\Create as EdgeCreate;
use App\Livewire\Edge\Import;
use App\Livewire\Edge\Index as EdgeIndex;
use App\Livewire\Edge\Templates;
use App\Livewire\Edge\Usage;
use App\Livewire\Fleet\BlastRadius as FleetBlastRadius;
use App\Livewire\Fleet\DeployContracts as FleetDeployContracts;
use App\Livewire\Fleet\Deploys as FleetDeploys;
use App\Livewire\Fleet\Domains as FleetDomains;
use App\Livewire\Fleet\EnvDrift as FleetEnvDrift;
use App\Livewire\Fleet\EnvSearch as FleetEnvSearch;
use App\Livewire\Fleet\Health as FleetHealth;
use App\Livewire\Fleet\Intelligence as FleetIntelligence;
use App\Livewire\Fleet\OpsCopilot as FleetOpsCopilot;
use App\Livewire\Fleet\Overview as FleetOverview;
use App\Livewire\Fleet\Previews as FleetPreviews;
use App\Livewire\Imports\Forge\Inventory;
use App\Livewire\Imports\Parity as ImportParity;
use App\Livewire\Imports\Ploi\Inventory as PloiInventory;
use App\Livewire\Imports\Ploi\MigrationProgress;
use App\Livewire\Infrastructure\Index as InfrastructureIndex;
use App\Livewire\Invitations\Accept as InvitationsAccept;
use App\Livewire\Launches\Create as LaunchesCreate;
use App\Livewire\Launches\FullStack as LaunchesFullStack;
use App\Livewire\Launches\Path as LaunchesPath;
use App\Livewire\Launches\StandbyBlueprint as LaunchesStandbyBlueprint;
use App\Livewire\Marketing\ComingSoonSignup as MarketingComingSoonSignup;
use App\Livewire\Marketplace\Index as MarketplaceIndex;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\Organizations\Activity as OrganizationsActivity;
use App\Livewire\Organizations\Automation as OrganizationsAutomation;
use App\Livewire\Organizations\Create as OrganizationsCreate;
use App\Livewire\Organizations\Index as OrganizationsIndex;
use App\Livewire\Organizations\Members as OrganizationsMembers;
use App\Livewire\Organizations\NotificationChannels as OrganizationsNotificationChannels;
use App\Livewire\Organizations\Realtime as OrganizationsRealtime;
use App\Livewire\Organizations\RealtimeAppShow as OrganizationsRealtimeShow;
use App\Livewire\Organizations\Secrets as OrganizationsSecrets;
use App\Livewire\Organizations\Settings as OrganizationsSettings;
use App\Livewire\Organizations\Show as OrganizationsShow;
use App\Livewire\Organizations\Teams as OrganizationsTeams;
use App\Livewire\OrgNetworking;
use App\Livewire\Profile\DeleteAccount as ProfileDeleteAccount;
use App\Livewire\Profile\Referrals as ProfileReferrals;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show as ProjectsShow;
use App\Livewire\Roadmap\Index as RoadmapIndex;
use App\Livewire\Scripts\Create as ScriptsCreate;
use App\Livewire\Scripts\Edit as ScriptsEdit;
use App\Livewire\Scripts\Index as ScriptsIndex;
use App\Livewire\Scripts\Marketplace as ScriptsMarketplace;
use App\Livewire\Serverless\Create as ServerlessCreate;
use App\Livewire\Serverless\Glue as ServerlessGlue;
use App\Livewire\Serverless\Index as ServerlessIndex;
use App\Livewire\Serverless\Journey as ServerlessJourney;
use App\Livewire\Servers\Create\StepReview as ServerCreateStepReview;
use App\Livewire\Servers\Create\StepType as ServerCreateStepType;
use App\Livewire\Servers\Create\StepWhat as ServerCreateStepWhat;
use App\Livewire\Servers\Create\StepWhere as ServerCreateStepWhere;
use App\Livewire\Servers\CreateManaged as ServerCreateManaged;
use App\Livewire\Servers\Deploys as ServerDeploys;
use App\Livewire\Servers\ImportFromDigitalOcean as ServersImportFromDigitalOcean;
use App\Livewire\Servers\Index as ServersIndex;
use App\Livewire\Servers\ProvisionJourney as ServerProvisionJourney;
use App\Livewire\Servers\WorkspaceActivity;
use App\Livewire\Servers\WorkspaceBackups;
use App\Livewire\Servers\WorkspaceBackupsPreview;
use App\Livewire\Servers\WorkspaceBlueprint;
use App\Livewire\Servers\WorkspaceBlueprintPreview;
use App\Livewire\Servers\WorkspaceCaches;
use App\Livewire\Servers\WorkspaceCertInventory;
use App\Livewire\Servers\WorkspaceCli;
use App\Livewire\Servers\WorkspaceCliPreview;
use App\Livewire\Servers\WorkspaceCluster;
use App\Livewire\Servers\WorkspaceConfiguration;
use App\Livewire\Servers\WorkspaceConsole;
use App\Livewire\Servers\WorkspaceConsolePreview;
use App\Livewire\Servers\WorkspaceCostCard;
use App\Livewire\Servers\WorkspaceCron;
use App\Livewire\Servers\WorkspaceDaemons;
use App\Livewire\Servers\WorkspaceDaemonSlo;
use App\Livewire\Servers\WorkspaceDatabases;
use App\Livewire\Servers\WorkspaceDeployPolicy;
use App\Livewire\Servers\WorkspaceDeployPolicyPreview;
use App\Livewire\Servers\WorkspaceDocker;
use App\Livewire\Servers\WorkspaceDockerPreview;
use App\Livewire\Servers\WorkspaceEdgeProxy;
use App\Livewire\Servers\WorkspaceErrors;
use App\Livewire\Servers\WorkspaceFiles;
use App\Livewire\Servers\WorkspaceFilesPreview;
use App\Livewire\Servers\WorkspaceFirewall;
use App\Livewire\Servers\WorkspaceHealth;
use App\Livewire\Servers\WorkspaceInsights;
use App\Livewire\Servers\WorkspaceInsightsPreview;
use App\Livewire\Servers\WorkspaceLoadBalancers;
use App\Livewire\Servers\WorkspaceLogs;
use App\Livewire\Servers\WorkspaceMaintenance;
use App\Livewire\Servers\WorkspaceMaintenancePreview;
use App\Livewire\Servers\WorkspaceManage;
use App\Livewire\Servers\WorkspaceMonitor;
use App\Livewire\Servers\WorkspaceNetworking;
use App\Livewire\Servers\WorkspaceNotifications;
use App\Livewire\Servers\WorkspaceOverview;
use App\Livewire\Servers\WorkspacePatchAdvisor;
use App\Livewire\Servers\WorkspacePhp;
use App\Livewire\Servers\WorkspaceReleaseHygiene;
use App\Livewire\Servers\WorkspaceReleaseHygienePreview;
use App\Livewire\Servers\WorkspaceRun;
use App\Livewire\Servers\WorkspaceRunPreview;
use App\Livewire\Servers\WorkspaceSchedule;
use App\Livewire\Servers\WorkspaceSecurityDigest;
use App\Livewire\Servers\WorkspaceSecurityDigestPreview;
use App\Livewire\Servers\WorkspaceServices;
use App\Livewire\Servers\WorkspaceSettings;
use App\Livewire\Servers\WorkspaceSharedHost;
use App\Livewire\Servers\WorkspaceSharedHostPreview;
use App\Livewire\Servers\WorkspaceSites;
use App\Livewire\Servers\WorkspaceSnapshots;
use App\Livewire\Servers\WorkspaceSshAccessGraph;
use App\Livewire\Servers\WorkspaceSshAccessGraphPreview;
use App\Livewire\Servers\WorkspaceSshKeys;
use App\Livewire\Servers\WorkspaceSystemUsers;
use App\Livewire\Servers\WorkspaceWebserver;
use App\Livewire\Servers\WorkspaceWorkerPool;
use App\Livewire\Settings\ApiKeys as SettingsApiKeys;
use App\Livewire\Settings\BackupConfigurations as SettingsBackupConfigurations;
use App\Livewire\Settings\BulkNotificationAssignments;
use App\Livewire\Settings\CliAuthentications as SettingsCliAuthentications;
use App\Livewire\Settings\Hub as SettingsHub;
use App\Livewire\Settings\NotificationChannels as SettingsNotificationChannels;
use App\Livewire\Settings\Security as SettingsSecurity;
use App\Livewire\Settings\SourceControl as SettingsSourceControl;
use App\Livewire\Settings\SshKeys as SettingsSshKeys;
use App\Livewire\Settings\WebserverTemplates as SettingsWebserverTemplates;
use App\Livewire\Sites\Caching;
use App\Livewire\Sites\Cdn;
use App\Livewire\Sites\ChooseApp as SitesChooseApp;
use App\Livewire\Sites\Create as SitesCreate;
use App\Livewire\Sites\CreateCustom as SitesCreateCustom;
use App\Livewire\Sites\Database as SitesDatabase;
use App\Livewire\Sites\DeploymentDetail as SitesDeploymentDetail;
use App\Livewire\Sites\DeploymentsList as SitesDeploymentsList;
use App\Livewire\Sites\DeploySyncGroups;
use App\Livewire\Sites\EdgeDeploymentDetail;
use App\Livewire\Sites\EdgePreviewComments;
use App\Livewire\Sites\EnvDiff as SitesEnvDiff;
use App\Livewire\Sites\Errors as SitesErrors;
use App\Livewire\Sites\Files;
use App\Livewire\Sites\Index as SitesIndex;
use App\Livewire\Sites\Logs as SitesLogs;
use App\Livewire\Sites\Monitor as SitesMonitor;
use App\Livewire\Sites\Repository;
use App\Livewire\Sites\ScaffoldJourney;
use App\Livewire\Sites\ServerlessRouting;
use App\Livewire\Sites\SiteClone as SitesClone;
use App\Livewire\Sites\SiteEnvironment;
use App\Livewire\Sites\SitePromote as SitesPromote;
use App\Livewire\Sites\WebserverConfig as SitesWebserverConfig;
use App\Livewire\Sites\Workers;
use App\Livewire\Sites\WorkspaceInsights as SitesWorkspaceInsights;
use App\Livewire\Sites\WorkspaceSystemd;
use App\Livewire\Status\PublicPage as StatusPublicPage;
use App\Livewire\StatusPages\Index as StatusPagesIndex;
use App\Livewire\StatusPages\Manage as StatusPagesManage;
use App\Livewire\Teams\NotificationChannels as TeamsNotificationChannels;
use App\Livewire\TwoFactor\Page as TwoFactorPage;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Support\Admin\AdminFeatureFlags;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['web', 'auth']]);

// Standalone diagnostic page for Redis-backend failures. Lives outside the
// `web` middleware group on purpose — StartSession/CSRF/Pennant all touch
// Cache, so if Redis is down a normal route would recurse on the very error
// it tries to render. This handler reads env vars only.
Route::get('/_redis-unreachable', function () {
    return response()->view('errors.redis-unreachable', [
        'message' => __('Connection timed out — see config block below.'),
        'host' => (string) env('REDIS_HOST', '127.0.0.1'),
        'port' => (string) env('REDIS_PORT', '6379'),
        'cacheStore' => (string) env('CACHE_STORE', 'database'),
        'queueConnection' => (string) env('QUEUE_CONNECTION', 'sync'),
        'timeout' => (string) env('REDIS_TIMEOUT', '2.0'),
    ], 503);
})->withoutMiddleware(['web']);

Route::match(['post', 'options'], '/hooks/sites/{site}/deploy', SiteDeployWebhookController::class)
    ->middleware(['throttle:site-webhook'])
    ->name('hooks.site.deploy');

Route::match(['post', 'options'], '/hooks/cloud/{site}/redeploy', CloudDeployWebhookController::class)
    ->middleware(['throttle:site-webhook'])
    ->name('hooks.cloud.redeploy');

Route::match(['post', 'options'], '/hooks/cloud/{site}/github', GithubCloudWebhookController::class)
    ->middleware(['throttle:site-webhook'])
    ->name('hooks.cloud.github');

Route::match(['post', 'options'], '/hooks/edge/{site}/github', GithubEdgeWebhookController::class)
    ->middleware(['throttle:site-webhook'])
    ->name('hooks.edge.github');

// Per-request log records POSTed by a deployed serverless function's handler
// — the ingest path behind the Logs page's Visits tab. HMAC-authenticated
// inside the controller; high throttle since it fires once per app request.
Route::post('/hooks/functions/{site}/log', FunctionLogIngestController::class)
    ->middleware(['throttle:function-log-ingest'])
    ->name('hooks.functions.log');

Route::post('/hooks/edge/{site}/log', EdgeLogIngestController::class)
    ->middleware(['throttle:function-log-ingest'])
    ->name('hooks.edge.log');

Route::post('/hooks/edge/{site}/vitals', EdgeVitalsIngestController::class)
    ->middleware(['throttle:function-log-ingest'])
    ->name('hooks.edge.vitals');

Route::post('/hooks/edge/logpush', EdgeLogpushIngestController::class)
    ->middleware(['throttle:function-log-ingest'])
    ->name('hooks.edge.logpush');

// Per-site deploy hooks (P10b). Match POST + GET so CMSes that only
// emit GET pings (Sanity, some Webflow integrations) still work.
// Rate-limit by IP via the cheap default throttle to slow brute-force.
Route::match(['get', 'post'], '/hooks/edge/deploy/{token}', EdgeDeployHookController::class)
    ->middleware(['throttle:60,1'])
    ->where('token', '[A-Za-z0-9]{16,64}')
    ->name('hooks.edge.deploy');

// Preview-comment widget REST endpoints. Public (no Laravel session);
// auth is per-parent widget token in X-Dply-Preview-Widget. CORS is
// echoed for testing-domain origins.
Route::match(['options'], '/api/edge/preview-comments/{site}', [EdgePreviewCommentsController::class, 'options']);
Route::get('/api/edge/preview-comments/{site}', [EdgePreviewCommentsController::class, 'index'])
    ->middleware(['throttle:function-log-ingest'])
    ->name('api.edge.preview-comments.index');
Route::post('/api/edge/preview-comments/{site}', [EdgePreviewCommentsController::class, 'store'])
    ->middleware(['throttle:function-log-ingest'])
    ->name('api.edge.preview-comments.store');

// Friendly public URL for a serverless function — dply proxies it through
// to the function's raw DigitalOcean Functions invocation URL.
Route::any('/fn/{slug}/{path?}', ServerlessFunctionProxyController::class)
    ->where('path', '.*')
    ->name('serverless.proxy');

// Live function hostnames: a deployed function answers at
// {slug}.{DPLY_TESTING_DOMAINS entry}. Each configured testing domain gets a
// wildcard-subdomain route that proxies to the function. (Production needs
// *.{domain} DNS + TLS pointed at the dply app for these to resolve.)
foreach ((array) config('services.digitalocean.testing_domains', []) as $functionDomain) {
    $functionDomain = trim((string) $functionDomain);
    if ($functionDomain === '') {
        continue;
    }

    Route::domain('{slug}.'.$functionDomain)
        ->any('/{path?}', ServerlessFunctionProxyController::class)
        ->where('path', '.*')
        ->withoutMiddleware([
            ValidateCsrfToken::class,
            RedirectGuestsToComingSoon::class,
        ]);
}

Route::get('/', function () {
    // The animated homepage is THE homepage — no classic/animated switching.
    return view('welcome-v2');
});

Route::get('/pricing', function () {
    return view('pricing');
})->name('pricing');

Route::get('/features', function () {
    return view('features');
})->name('features');

Route::get('/changelog', function () {
    return view('changelog');
})->name('changelog');

Route::livewire('/roadmap', RoadmapIndex::class)
    ->middleware(['throttle:60,1'])
    ->name('roadmap');

Route::get('/migrate', function () {
    return view('migrate.index', [
        'sources' => config('migration_sources', []),
    ]);
})->name('migrate.index');

Route::get('/deploy', function (Request $request) {
    $allowed = ['repo', 'branch', 'name', 'runtime_mode', 'build_command', 'output_dir'];
    $query = array_filter(
        $request->only($allowed),
        static fn ($v): bool => is_string($v) && $v !== '',
    );

    return redirect()->route('edge.create', $query);
})->name('deploy.shortlink');

Route::get('/migrate/{slug}', function (string $slug) {
    $source = config('migration_sources.'.$slug);

    abort_unless($source, 404);

    return view('migrate.show', [
        'slug' => $slug,
        'source' => $source,
    ]);
})->whereIn('slug', array_keys(config('migration_sources', [])))
    ->name('migrate.show');

Route::livewire('/coming-soon', MarketingComingSoonSignup::class)
    ->name('coming-soon');

Route::livewire('/status/{statusPage}', StatusPublicPage::class)
    ->middleware(['throttle:120,1'])
    ->name('status.public');

Route::get('/share/database-credentials/{token}', [DatabaseCredentialShareController::class, 'show'])
    ->middleware(['throttle:60,1'])
    ->name('database-credential-shares.show');

Route::get('/share/server-credentials/{token}', [ServerCredentialShareController::class, 'show'])
    ->middleware(['throttle:60,1'])
    ->name('server-credential-shares.show');

Route::prefix('cli')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/install.sh', [CliInstallController::class, 'installScript'])->name('cli.install');
    Route::get('/dply-cli.tgz', [CliInstallController::class, 'packageTarball'])->name('cli.package');
    Route::get('/version.json', [CliInstallController::class, 'packageVersion'])->name('cli.version');
});

Route::middleware(['auth', 'verified', 'org'])->group(function () {
    Route::livewire('invitations/accept/{token}', InvitationsAccept::class)->name('invitations.accept');
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('/networking', OrgNetworking::class)->name('networking.index');
    // OAuth-style device-flow approval page for the dply CLI. The CLI
    // prints a short code; user lands here (deep link or paste),
    // confirms scopes + org, and we mint an ApiToken that the polling
    // CLI picks up exactly once via /api/v1/auth/device/poll.
    Route::livewire('/auth/device', AuthDeviceApproval::class)->name('auth.device.show');
    Route::get('/edge/sites/{site}/preview-access', EdgePreviewAccessController::class)
        ->name('edge.preview-access');
    Route::livewire('infrastructure', InfrastructureIndex::class)->name('infrastructure.index');
    Route::middleware('feature:surface.fleet')->group(function (): void {
        Route::livewire('/fleet', FleetOverview::class)->name('fleet.index');
        Route::livewire('/fleet/health', FleetHealth::class)->name('fleet.health');
        Route::livewire('/fleet/domains', FleetDomains::class)->name('fleet.domains');
        Route::livewire('/fleet/env-search', FleetEnvSearch::class)->name('fleet.env-search');
        Route::livewire('/fleet/env-drift', FleetEnvDrift::class)->name('fleet.env-drift');
        Route::livewire('/fleet/intelligence', FleetIntelligence::class)->name('fleet.intelligence');
        Route::livewire('/fleet/deploys', FleetDeploys::class)->name('fleet.deploys');
        Route::livewire('/fleet/blast-radius', FleetBlastRadius::class)->name('fleet.blast-radius');
        Route::livewire('/fleet/previews', FleetPreviews::class)->name('fleet.previews');
        Route::livewire('/fleet/deploy-contracts', FleetDeployContracts::class)->name('fleet.deploy-contracts');
        Route::livewire('/fleet/copilot', FleetOpsCopilot::class)
            ->middleware('feature:global.ops_copilot')
            ->name('fleet.copilot');
    });
    Route::prefix('admin')
        ->middleware('can:viewPlatformAdmin')
        ->name('admin.')
        ->group(function (): void {
            Route::livewire('/', AdminOverview::class)->name('overview');
            Route::livewire('/operations', AdminOperations::class)->name('operations');
            Route::livewire('/audit', AdminAuditLog::class)->name('audit');
            Route::livewire('/roadmap', AdminRoadmapIndex::class)->name('roadmap.index');
            Route::livewire('/flags/global', AdminGlobalFlags::class)->name('flags.global');
            Route::livewire('/flags/vm/servers', AdminProductLineFlags::class)->defaults('line', 'vm-servers')->name('flags.vm.servers');
            Route::livewire('/flags/vm/sites', AdminProductLineFlags::class)->defaults('line', 'vm-sites')->name('flags.vm.sites');
            Route::livewire('/flags/cloud', AdminProductLineFlags::class)->defaults('line', 'cloud')->name('flags.cloud');
            Route::livewire('/flags/edge', AdminProductLineFlags::class)->defaults('line', 'edge')->name('flags.edge');
            Route::livewire('/flags/serverless', AdminProductLineFlags::class)->defaults('line', 'serverless')->name('flags.serverless');
            Route::livewire('/flags/platform', AdminProductLineFlags::class)->defaults('line', 'platform')->name('flags.platform');
            Route::get('/flags/defaults/{group}', function (string $group) {
                $target = AdminFeatureFlags::legacyDefaultGroupRedirectTarget($group);
                if ($target === null) {
                    abort(404);
                }

                $routeName = AdminFeatureFlags::productLineRoute($target);
                if ($routeName === null) {
                    abort(404);
                }

                return redirect()->route($routeName);
            })->name('flags.defaults');
            Route::livewire('/organizations', AdminOrganizationsIndex::class)->name('organizations.index');
            Route::livewire('/organizations/{organization}', AdminOrganizationsShow::class)->name('organizations.show');
            Route::livewire('/beta-invites', AdminBetaInvites::class)->name('beta-invites');
            Route::livewire('/coming-soon-access', AdminComingSoonAccess::class)->name('coming-soon-access');
        });
    Route::redirect('/admin/dashboard', '/admin')->middleware('can:viewPlatformAdmin')->name('admin.dashboard');
    Route::middleware('feature:surface.marketplace')->group(function (): void {
        Route::livewire('/marketplace', MarketplaceIndex::class)->name('marketplace.index');
    });

    Route::get('/docs', [DocsController::class, 'index'])->name('docs.index');
    Route::get('/docs/search-index.json', [DocsController::class, 'searchIndex'])->name('docs.search-index');
    Route::get('/docs/connect-provider', [DocsController::class, 'connectProvider'])->name('docs.connect-provider');
    Route::get('/docs/create-first-server', [DocsController::class, 'createFirstServer'])->name('docs.create-first-server');
    Route::get('/docs/api', [DocsController::class, 'apiDocumentation'])->name('docs.api');
    // Slug is validated by the renderer (manifest + config fallback) — a kebab
    // pattern keeps the route from swallowing unrelated paths; unknown slugs 404.
    Route::get('/docs/{slug}', [DocsController::class, 'markdown'])
        ->where('slug', '[a-z0-9-]+')
        ->name('docs.markdown');

    Route::redirect('/settings', '/settings/profile')->name('settings.index');
    Route::livewire('/settings/profile', SettingsHub::class)->name('settings.profile');
    Route::livewire('/settings/servers', SettingsHub::class)->name('settings.servers');
    Route::livewire('/notifications', NotificationsIndex::class)->name('notifications.index');
    Route::livewire('/deploy-sync', DeploySyncGroups::class)->name('deploy-sync.index');

    Route::livewire('/profile/referrals', ProfileReferrals::class)->name('profile.referrals');
    Route::livewire('/profile/security', SettingsSecurity::class)->name('profile.security');
    Route::livewire('/profile/source-control', SettingsSourceControl::class)->name('profile.source-control');
    Route::livewire('/profile/ssh-keys', SettingsSshKeys::class)->name('profile.ssh-keys');
    Route::livewire('/profile/api-keys', SettingsApiKeys::class)->name('profile.api-keys');
    Route::livewire('/profile/cli', SettingsCliAuthentications::class)->name('profile.cli');
    Route::livewire('/profile/backup-configurations', SettingsBackupConfigurations::class)->name('profile.backup-configurations');
    Route::livewire('/profile/notification-channels', SettingsNotificationChannels::class)->name('profile.notification-channels');
    Route::livewire('/profile/notification-channels/bulk-assign', BulkNotificationAssignments::class)->name('profile.notification-channels.bulk-assign');
    Route::livewire('/profile/delete-account', ProfileDeleteAccount::class)->name('profile.delete-account');

    Route::livewire('/profile/two-factor', TwoFactorPage::class)->name('two-factor.setup');

    Route::livewire('organizations', OrganizationsIndex::class)->name('organizations.index');
    Route::livewire('organizations/create', OrganizationsCreate::class)->name('organizations.create');
    Route::livewire('organizations/{organization}', OrganizationsShow::class)->name('organizations.show');
    Route::livewire('organizations/{organization}/settings', OrganizationsSettings::class)->name('organizations.settings');
    Route::livewire('organizations/{organization}/members', OrganizationsMembers::class)->name('organizations.members');
    Route::livewire('organizations/{organization}/teams', OrganizationsTeams::class)->name('organizations.teams');
    Route::livewire('organizations/{organization}/activity', OrganizationsActivity::class)->name('organizations.activity');
    Route::get('organizations/{organization}/compliance-export', OrganizationComplianceExportController::class)->name('organizations.compliance-export');
    Route::livewire('organizations/{organization}/automation', OrganizationsAutomation::class)->name('organizations.automation');
    Route::livewire('organizations/{organization}/notification-channels', OrganizationsNotificationChannels::class)->name('organizations.notification-channels');
    Route::livewire('organizations/{organization}/teams/{team}/notification-channels', TeamsNotificationChannels::class)->name('teams.notification-channels');
    Route::livewire('organizations/{organization}/billing', BillingShow::class)->name('billing.show');
    Route::livewire('organizations/{organization}/billing/analytics', BillingAnalytics::class)->name('billing.analytics');
    Route::livewire('organizations/{organization}/subscription', BillingShow::class)->name('subscription.show');
    Route::livewire('organizations/{organization}/invoices', BillingInvoices::class)->name('billing.invoices');
    Route::livewire('organizations/{organization}/realtime', OrganizationsRealtime::class)->name('organizations.realtime');
    Route::livewire('organizations/{organization}/realtime/{realtimeApp}', OrganizationsRealtimeShow::class)->name('organizations.realtime.show');
    Route::livewire('organizations/{organization}/credentials', CredentialsIndex::class)->name('organizations.credentials');
    Route::livewire('organizations/{organization}/secrets', OrganizationsSecrets::class)->name('organizations.secrets');
    Route::livewire('organizations/{organization}/webserver-templates', SettingsWebserverTemplates::class)->name('organizations.webserver-templates');

    Route::livewire('/backups', BackupsDatabases::class)->name('backups.databases');
    Route::redirect('/backups/databases', '/backups', 301);
    Route::livewire('/backups/files', BackupsFiles::class)->name('backups.files');

    Route::middleware('feature:surface.scripts')->group(function (): void {
        Route::livewire('scripts', ScriptsIndex::class)->name('scripts.index');
        Route::livewire('scripts/marketplace', ScriptsMarketplace::class)->name('scripts.marketplace');
        Route::livewire('scripts/create', ScriptsCreate::class)->name('scripts.create');
        Route::livewire('scripts/{script}/edit', ScriptsEdit::class)->name('scripts.edit');
    });

    Route::livewire('sites', SitesIndex::class)->name('sites.index');
    Route::middleware('feature:surface.cloud')->group(function (): void {
        Route::livewire('cloud', CloudIndex::class)->name('cloud.index');
        Route::livewire('cloud/create', CloudCreate::class)->name('cloud.create');
        Route::livewire('cloud/databases', CloudDatabaseIndex::class)->name('cloud.databases.index');
        Route::livewire('cloud/databases/create', CloudDatabaseCreate::class)->name('cloud.databases.create');
    });
    Route::middleware('feature:surface.edge')->group(function (): void {
        Route::livewire('edge', EdgeIndex::class)->name('edge.index');
        Route::livewire('edge/create', EdgeCreate::class)->name('edge.create');
        Route::livewire('edge/import', Import::class)->name('edge.import');
        Route::livewire('edge/templates', Templates::class)->name('edge.templates');
        Route::livewire('edge/usage', Usage::class)->name('edge.usage');
    });
    Route::middleware('feature:surface.serverless')->group(function (): void {
        Route::livewire('serverless', ServerlessIndex::class)->name('serverless.index');
        Route::livewire('serverless/glue', ServerlessGlue::class)->name('serverless.glue');
        Route::livewire('serverless/create', ServerlessCreate::class)->name('serverless.create');
        Route::livewire('servers/{server}/sites/{site}/deploying', ServerlessJourney::class)->name('serverless.journey');
    });
    Route::livewire('imports/parity', ImportParity::class)->name('imports.parity');
    Route::livewire('imports/ploi', PloiInventory::class)->name('imports.ploi.inventory');
    Route::livewire('imports/ploi/migrations/{migration}', MigrationProgress::class)->name('imports.ploi.migration.progress');
    Route::livewire('imports/forge', Inventory::class)->name('imports.forge.inventory');
    Route::middleware('feature:surface.projects')->group(function (): void {
        Route::livewire('projects', ProjectsIndex::class)->name('projects.index');
        Route::livewire('projects/{workspace}', ProjectsShow::class)->defaults('section', 'overview')->name('projects.show');
        Route::livewire('projects/{workspace}/overview', ProjectsShow::class)->defaults('section', 'overview')->name('projects.overview');
        Route::livewire('projects/{workspace}/resources', ProjectsShow::class)->defaults('section', 'resources')->name('projects.resources');
        Route::livewire('projects/{workspace}/access', ProjectsShow::class)->defaults('section', 'access')->name('projects.access');
        Route::livewire('projects/{workspace}/operations', ProjectsShow::class)->defaults('section', 'operations')->name('projects.operations');
        Route::livewire('projects/{workspace}/delivery', ProjectsShow::class)->defaults('section', 'delivery')->name('projects.delivery');
    });
    Route::middleware('feature:surface.status_pages')->group(function (): void {
        Route::livewire('status-pages', StatusPagesIndex::class)->name('status-pages.index');
        Route::livewire('status-pages/{statusPage}', StatusPagesManage::class)->name('status-pages.manage');
    });
    Route::livewire('launches/create', LaunchesCreate::class)->name('launches.create');
    Route::middleware('feature:launch.full_stack_wizard')->group(function (): void {
        Route::livewire('launches/full-stack', LaunchesFullStack::class)->name('launches.full-stack');
    });
    Route::middleware('feature:launch.standby_blueprint')->group(function (): void {
        Route::livewire('launches/standby', LaunchesStandbyBlueprint::class)->name('launches.standby');
    });
    // Container flow inversion (2026-05): the standalone container launcher is gone.
    // Container apps are now created server-first (host via /servers/create wizard,
    // container via /servers/{id}/sites/create container mode). This route is kept for
    // one release as a 302 to the wizard so external bookmarks don't 404.
    Route::redirect('launches/containers/create', '/servers/create?host_target=docker', 302)->name('launches.containers.create');
    Route::middleware('feature:surface.serverless')->group(function (): void {
        Route::livewire('launches/serverless', LaunchesPath::class)->defaults('path', 'serverless')->name('launches.serverless');
    });
    Route::livewire('launches/kubernetes', LaunchesPath::class)->defaults('path', 'kubernetes')->name('launches.kubernetes');
    Route::livewire('launches/cloud-network', LaunchesPath::class)->defaults('path', 'cloud-network')->name('launches.cloud-network');

    Route::livewire('servers', ServersIndex::class)->name('servers.index');
    Route::livewire('servers/import/digitalocean', ServersImportFromDigitalOcean::class)->name('servers.import.digitalocean');
    // Multi-step server-create wizard. /servers/create is Step 1 directly; if a draft
    // is past step 1, StepType::mount() redirects on to the current step.
    Route::middleware('vm.platform')->group(function (): void {
        // dply-managed servers: dply provisions/pays for the VM on its own infra
        // and bills all-in cost-plus. Gated by surface.managed_servers in mount().
        Route::livewire('servers/create/managed', ServerCreateManaged::class)->name('servers.create.managed');
        Route::livewire('servers/create', ServerCreateStepType::class)->name('servers.create');
        Route::livewire('servers/create/where', ServerCreateStepWhere::class)->name('servers.create.where');
        Route::livewire('servers/create/what', ServerCreateStepWhat::class)->name('servers.create.what');
        Route::livewire('servers/create/review', ServerCreateStepReview::class)->name('servers.create.review');
    });
    Route::get('servers/{server}', function (Server $server) {
        Gate::authorize('view', $server);

        // Kubernetes hosts have their own dedicated page (cluster lifecycle +
        // management). Docker / other non-VM hosts still go to the generic
        // overview.
        if (($server->meta['host_kind'] ?? null) === Server::HOST_KIND_KUBERNETES) {
            return redirect()->route('servers.cluster', $server);
        }

        // A serverless function is not a server — the DO Functions namespace
        // is an implementation detail. Send the operator straight to the
        // function workspace instead of a server-shaped overview.
        if ($server->isDigitalOceanFunctionsHost()) {
            $function = $server->sites()->orderBy('created_at')->first();
            if ($function !== null) {
                return redirect()->route('sites.show', ['server' => $server, 'site' => $function]);
            }
        }

        // Journey page is SSH/VM-shaped — only VM hosts have a provision task
        // and the InteractsWithServerWorkspace boot rejects non-VM hosts from
        // routing through it (which previously caused a redirect loop for
        // freshly-provisioning DOKS clusters). Container hosts always go to
        // the workspace overview, which surfaces a "cluster is provisioning"
        // banner when status is PENDING/PROVISIONING.
        if (! $server->isVmHost()) {
            return redirect()->route('servers.overview', $server);
        }

        if ($server->status === Server::STATUS_PENDING || $server->status === Server::STATUS_PROVISIONING) {
            return redirect()->route('servers.journey', $server);
        }

        if ($server->status === Server::STATUS_READY
            && RunSetupScriptJob::shouldDispatch($server)
            && $server->setup_status !== Server::SETUP_STATUS_DONE) {
            return redirect()->route('servers.journey', $server);
        }

        return redirect()->route('servers.overview', $server);
    })->name('servers.show');
    Route::livewire('servers/{server}/journey', ServerProvisionJourney::class)->name('servers.journey');
    Route::post('servers/{server}/cancel-provision', CancelServerProvisionController::class)->name('servers.cancel-provision');
    Route::middleware('feature:workspace.cluster')->group(function (): void {
        Route::livewire('servers/{server}/cluster', WorkspaceCluster::class)->name('servers.cluster');
    });
    Route::get('servers/{server}/cluster/kubeconfig', function (Server $server) {
        Gate::authorize('view', $server);
        $kubeconfig = (string) ($server->meta['kubernetes']['kubeconfig'] ?? '');
        if ($kubeconfig === '') {
            abort(404, 'kubeconfig not available yet');
        }

        $clusterName = (string) ($server->meta['kubernetes']['cluster_name'] ?? 'cluster');

        return response($kubeconfig, 200, [
            'Content-Type' => 'application/yaml',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $clusterName).'-kubeconfig.yaml"',
        ]);
    })->name('servers.cluster.kubeconfig');
    Route::livewire('servers/{server}/sites/create', SitesCreate::class)->name('sites.create');
    Route::livewire('servers/{server}/sites/create-custom', SitesCreateCustom::class)->name('sites.create-custom');
    Route::livewire('servers/{server}/sites/{site}/scaffold-journey', ScaffoldJourney::class)->name('sites.scaffold-journey');
    Route::livewire('servers/{server}/sites/{site}/choose-app', SitesChooseApp::class)->name('sites.choose-app');
    // The first-deploy setup wizard was folded into the Repository page as a
    // conditional tab. Keep the route name working by redirecting to it.
    Route::get('servers/{server}/sites/{site}/setup', function (Server $server, Site $site) {
        return redirect()->route('sites.repository', ['server' => $server, 'site' => $site, 'repo_tab' => 'setup']);
    })->name('sites.setup');
    Route::livewire('servers/{server}/sites/{site}/clone', SitesClone::class)->name('sites.clone');
    Route::middleware('feature:workspace.site_promote')->group(function (): void {
        Route::livewire('servers/{server}/sites/{site}/promote', SitesPromote::class)->name('sites.promote');
    });
    Route::livewire('servers/{server}/sites/{site}/env-diff', SitesEnvDiff::class)->name('sites.env-diff');
    Route::get('servers/{server}/sites/{site}/deploy', function (Server $server, Site $site) {
        return redirect()->route('sites.deployments.index', ['server' => $server, 'site' => $site] + request()->query());
    });
    Route::get('servers/{server}/sites/{site}/pipeline', function (Server $server, Site $site) {
        return redirect()->route('sites.deployments.index', [
            'server' => $server,
            'site' => $site,
            'tab' => 'pipeline',
        ] + request()->query());
    })->name('sites.pipeline');
    Route::livewire('servers/{server}/sites/{site}/deployments', SitesDeploymentsList::class)->name('sites.deployments.index');
    Route::livewire('servers/{server}/sites/{site}/deployments/{deployment}', SitesDeploymentDetail::class)->name('sites.deployments.show');
    Route::livewire('servers/{server}/sites/{site}/edge/deployments/{deployment}', EdgeDeploymentDetail::class)->name('sites.edge.deployments.show');
    Route::livewire('servers/{server}/sites/{site}/cloud/deploys/{deploy}', DeployDetail::class)
        ->name('sites.cloud.deploys.show');
    Route::livewire('servers/{server}/sites/{site}/insights', SitesWorkspaceInsights::class)->name('sites.insights');
    Route::livewire('servers/{server}/sites/{site}/webserver-config', SitesWebserverConfig::class)->name('sites.webserver-config');
    Route::livewire('servers/{server}/sites/{site}/monitor', SitesMonitor::class)->name('sites.monitor');
    Route::livewire('servers/{server}/sites/{site}/errors', SitesErrors::class)->name('sites.errors');
    Route::livewire('servers/{server}/sites/{site}/logs', SitesLogs::class)->name('sites.logs');
    // Commits were merged into the Repository page as a tab. Keep the route
    // name working by redirecting to repository?tab=commits.
    Route::get('servers/{server}/sites/{site}/commits', function (Server $server, Site $site) {
        return redirect()->route('sites.repository', ['server' => $server, 'site' => $site, 'repo_tab' => 'commits']);
    })->name('sites.commits');
    // Site-level crontab management was removed — cron is a host-level concern,
    // managed on the server Cron page (servers.cron, filterable by ?site=).
    // Site scheduling lives on Schedule (framework scheduler) + Workers (daemons).
    Route::livewire('servers/{server}/sites/{site}/preview-comments', EdgePreviewComments::class)->name('sites.preview-comments');
    Route::livewire('servers/{server}/sites/{site}/daemons', WorkspaceDaemons::class)->name('sites.daemons');
    Route::livewire('servers/{server}/sites/{site}/services', WorkspaceSystemd::class)->name('sites.services');
    Route::get('servers/{server}/sites/{site}/queue-workers', function (Server $server, Site $site) {
        return redirect()->route('sites.daemons', ['server' => $server, 'site' => $site] + request()->query());
    })->name('sites.queue-workers');
    Route::livewire('servers/{server}/sites/{site}/backups', WorkspaceBackups::class)->name('sites.backups');
    // BACKGROUND group for container/serverless workspaces — engine-level
    // schedule + workers (one minute-cadence tick today, list-of-rules in
    // future iterations).
    // Site-kind dispatch: VM → WorkspaceSchedule, container/serverless → Schedule
    // (see SiteScheduleController). One canonical /schedule URL for any site.
    Route::get('servers/{server}/sites/{site}/schedule', SiteScheduleController::class)->name('sites.schedule');
    Route::livewire('servers/{server}/sites/{site}/workers', Workers::class)->name('sites.workers');
    // Unified Resources surface. Routes through the site workspace controller
    // (same chrome + Settings/EdgeSettings dispatch as sites.show) on the
    // `resources` section: VM sites render the new bindings hub, container sites
    // render the Cloud resources panel — both inside the normal workspace, at
    // one canonical /resources URL. (Was a standalone Cloud-only component.)
    Route::get('servers/{server}/sites/{site}/resources', SiteWorkspaceController::class)
        ->defaults('section', 'resources')
        ->name('sites.resources');
    // Standalone Environment page — first-class, no longer a Deployments-hub tab.
    Route::livewire('servers/{server}/sites/{site}/environment', SiteEnvironment::class)->name('sites.environment');
    // NETWORKING group for serverless / container workspaces — manages the dply
    // edge proxy (hostname/DNS, custom domains, redirects, headers + CORS,
    // invocation URLs). MUST live on its own path: the generic VM routing surface
    // is `sites.show` section=routing → `/sites/{site}/routing`. Sharing that path
    // let this literal route shadow the wildcard for *every* site, so a VM site's
    // /routing hit ServerlessRouting, which redirects VM sites back to
    // section=routing → the same URL → an infinite redirect loop. The dedicated
    // `/edge-routing` path keeps the two surfaces separate (name unchanged, so
    // callers using route('sites.routing') need no edits).
    Route::livewire('servers/{server}/sites/{site}/edge-routing', ServerlessRouting::class)->name('sites.routing');
    // Repository now lives as the top-level "Repository" tab on the
    // Deployments page (it used to be the "Settings → Repository" section,
    // but Settings was split into the Webhook/Hooks tabs and Repository was
    // promoted to its own tab). The /source URL redirects there so existing
    // bookmarks keep working. Any incoming `?tab=` query (e.g. ?tab=commits
    // from the commits redirect above) is forwarded as `?repo_tab=` so the
    // embedded Repository component can pick its sub-tab without colliding
    // with the deployments page's own ?tab= param.
    // Repository is its own first-class standalone page (renders with the site
    // sidebar — the component already supports non-embedded mode), so it's
    // reachable straight from the nav instead of only as a Deployments tab.
    Route::livewire('servers/{server}/sites/{site}/repository', Repository::class)->name('sites.repository');
    // Legacy /source bookmarks → the standalone Repository page, forwarding any
    // ?tab= as the component's ?repo_tab= sub-tab.
    Route::get('servers/{server}/sites/{site}/source', function (Server $server, Site $site) {
        $query = request()->query();
        if (isset($query['tab'])) {
            $query['repo_tab'] = $query['tab'];
            unset($query['tab']);
        }

        return redirect()->route('sites.repository', ['server' => $server, 'site' => $site] + $query);
    })->name('sites.source');
    Route::livewire('servers/{server}/sites/{site}/caching', Caching::class)->name('sites.caching');
    Route::livewire('servers/{server}/sites/{site}/cdn', Cdn::class)->name('sites.cdn');
    Route::livewire('servers/{server}/sites/{site}/database', SitesDatabase::class)->name('sites.database');
    Route::livewire('servers/{server}/sites/{site}/files', Files::class)->name('sites.files');
    Route::get('servers/{server}/sites/{site}/files/download', SiteFileDownloadController::class)->name('sites.files.download');
    // Quick downloads now queue + stage to the download bucket; this signed,
    // login-gated route streams the staged artifact once then deletes it.
    Route::get('quick-downloads/{quickDownload}/fetch', [QuickDownloadController::class, 'fetch'])
        ->middleware('signed')
        ->name('quick-download.fetch');
    // Legacy redirect for the previous URL shape /sites/{site}/settings/{section}. The
    // {section} is required — without it the bare /sites/{site}/settings URL collides
    // with the new "Settings" tab on the wildcard route below, which sends you back to
    // General. Operators who bookmarked the bare /settings URL now land on the new
    // Settings tab (Site identity, Web directory, Project, Notes), which is the
    // intended behavior after the General → Settings IA split.
    Route::get('servers/{server}/sites/{site}/settings/{section}', function (Server $server, Site $site, string $section) {
        $targetSection = $section;
        $query = request()->query();

        if ($targetSection === 'webhooks') {
            $targetSection = 'notifications';
        } elseif ($targetSection === 'deploy') {
            return redirect()->route('sites.deployments.index', ['server' => $server, 'site' => $site] + $query);
        } elseif ($targetSection === 'pipeline') {
            return redirect()->route('sites.deployments.index', [
                'server' => $server,
                'site' => $site,
                'tab' => 'pipeline',
            ] + $query);
        } elseif ($targetSection === 'dns') {
            $query['tab'] = 'dns';
            $targetSection = 'routing';
        } elseif (in_array($targetSection, ['domains', 'aliases', 'redirects', 'preview', 'tenants'], true)) {
            $query['tab'] = $targetSection;
            $targetSection = 'routing';
        } elseif (in_array($targetSection, ['runtime-php', 'runtime-ruby', 'runtime-static'], true)) {
            $query['tab'] = match ($targetSection) {
                'runtime-php' => 'php',
                'runtime-ruby' => 'ruby',
                'runtime-static' => 'static',
            };
            $targetSection = 'runtime';
        }

        return redirect()->route('sites.show', [
            'server' => $server,
            'site' => $site,
            'section' => $targetSection,
            ...$query,
        ]);
    })->name('sites.settings');

    // Edge access log CSV download — session-authed (Gate view-checked
    // inside the controller) so the dashboard "Download CSV" button
    // works without minting an API token. Stays out of the section
    // dispatcher because the .csv extension wouldn't match.
    Route::get('servers/{server}/sites/{site}/edge/logs.csv', EdgeLogCsvDownloadController::class)
        ->name('sites.edge.logs.csv');

    // Per-site audit-log export (CSV/JSON) — session-authed, no row cap,
    // mirrors the on-screen Audit log panel filters.
    Route::get('servers/{server}/sites/{site}/edge/audit.export', EdgeAuditLogExportController::class)
        ->name('sites.edge.audit.export');

    // Generate dply.yaml from the site's current declarative config
    // (redirects / rewrites / headers / crons). Lets a user export
    // dashboard-managed state to a repo-checked file.
    Route::get('servers/{server}/sites/{site}/edge/dply.yaml', EdgeRepoConfigYamlDownloadController::class)
        ->name('sites.edge.dply-yaml');

    Route::get('servers/{server}/sites/{site}/{section?}', SiteWorkspaceController::class)
        ->where('section', '[a-z0-9-]+')
        ->defaults('section', 'general')
        ->name('sites.show');
    Route::livewire('servers/{server}/sites', WorkspaceSites::class)->name('servers.sites');
    Route::middleware('feature:workspace.health')->group(function (): void {
        Route::livewire('servers/{server}/health', WorkspaceHealth::class)->name('servers.health');
    });
    Route::livewire('servers/{server}/blueprint', WorkspaceBlueprint::class)->name('servers.blueprint');
    Route::livewire('servers/{server}/blueprint-preview', WorkspaceBlueprintPreview::class)->name('servers.blueprint-preview');
    // No feature middleware: the component renders the full workspace when
    // workspace.server_maintenance is on, or the coming-soon teaser when it is
    // off but workspace.server_maintenance_preview is on (else 404).
    Route::livewire('servers/{server}/maintenance', WorkspaceMaintenance::class)->name('servers.maintenance');
    Route::livewire('servers/{server}/maintenance-preview', WorkspaceMaintenancePreview::class)->name('servers.maintenance-preview');
    Route::middleware('feature:workspace.patch_advisor')->group(function (): void {
        Route::livewire('servers/{server}/patches', WorkspacePatchAdvisor::class)->name('servers.patches');
    });
    // No feature middleware: the component renders the full workspace when
    // workspace.release_hygiene is on, or the coming-soon teaser when it is
    // off but workspace.release_hygiene_preview is on (else 404).
    Route::livewire('servers/{server}/hygiene', WorkspaceReleaseHygiene::class)->name('servers.hygiene');
    Route::livewire('servers/{server}/hygiene-preview', WorkspaceReleaseHygienePreview::class)->name('servers.hygiene-preview');
    Route::livewire('servers/{server}/worker-slo', WorkspaceDaemonSlo::class)->name('servers.worker-slo');
    Route::middleware('feature:workspace.cert_inventory')->group(function (): void {
        Route::livewire('servers/{server}/cert-inventory', WorkspaceCertInventory::class)->name('servers.cert-inventory');
    });
    // No feature middleware: the component renders the full workspace when
    // workspace.deploy_windows is on, or the coming-soon teaser when it is
    // off but workspace.deploy_windows_preview is on (else 404).
    Route::livewire('servers/{server}/deploy-policy', WorkspaceDeployPolicy::class)->name('servers.deploy-policy');
    Route::livewire('servers/{server}/deploy-policy-preview', WorkspaceDeployPolicyPreview::class)->name('servers.deploy-policy-preview');
    // No feature middleware: the component renders the full workspace when
    // workspace.ssh_access_graph is on, or the coming-soon teaser when it is
    // off but workspace.ssh_access_graph_preview is on (else 404).
    Route::livewire('servers/{server}/ssh-access', WorkspaceSshAccessGraph::class)->name('servers.ssh-access');
    Route::livewire('servers/{server}/ssh-access-preview', WorkspaceSshAccessGraphPreview::class)->name('servers.ssh-access-preview');
    Route::middleware('feature:workspace.server_cost')->group(function (): void {
        Route::livewire('servers/{server}/cost', WorkspaceCostCard::class)->name('servers.cost');
    });
    // No feature middleware: the component renders the full workspace when
    // workspace.security_digest is on, or the coming-soon teaser when it is
    // off but workspace.security_digest_preview is on (else 404).
    Route::livewire('servers/{server}/security-digest', WorkspaceSecurityDigest::class)->name('servers.security-digest');
    Route::livewire('servers/{server}/security-digest-preview', WorkspaceSecurityDigestPreview::class)->name('servers.security-digest-preview');
    Route::livewire('servers/{server}/insights', WorkspaceInsights::class)->name('servers.insights');
    Route::livewire('servers/{server}/insights-preview', WorkspaceInsightsPreview::class)->name('servers.insights-preview');
    Route::livewire('servers/{server}/shared-host', WorkspaceSharedHost::class)->name('servers.shared-host');
    Route::livewire('servers/{server}/shared-host-preview', WorkspaceSharedHostPreview::class)->name('servers.shared-host-preview');
    Route::livewire('servers/{server}/overview', WorkspaceOverview::class)->name('servers.overview');
    Route::livewire('servers/{server}/deploys', ServerDeploys::class)->name('servers.deploys');
    Route::livewire('servers/{server}/monitor', WorkspaceMonitor::class)->name('servers.monitor');
    Route::middleware('feature:workspace.activity')->group(function (): void {
        Route::livewire('servers/{server}/activity', WorkspaceActivity::class)->name('servers.activity');
    });
    Route::middleware('feature:workspace.services')->group(function (): void {
        Route::livewire('servers/{server}/services', WorkspaceServices::class)->name('servers.services');
    });
    Route::livewire('servers/{server}/php', WorkspacePhp::class)->name('servers.php');
    Route::livewire('servers/{server}/webserver', WorkspaceWebserver::class)->name('servers.webserver');
    Route::livewire('servers/{server}/edge-proxy', WorkspaceEdgeProxy::class)->name('servers.edge-proxy');
    Route::get('servers/{server}/webserver/caddy/admin-api/{path?}', CaddyAdminApiProxyController::class)
        ->where('path', '.*')
        ->name('servers.webserver.caddy.admin-api');
    Route::get('servers/{server}/traefik/dashboard/{path?}', TraefikDashboardProxyController::class)
        ->where('path', '.*')
        ->name('servers.traefik.dashboard');
    Route::get('servers/{server}/traefik/api/{path?}', TraefikDashboardProxyController::class)
        ->where('path', '.*')
        ->name('servers.traefik.api');
    Route::get('servers/{server}/traefik/assets/{path}', TraefikDashboardProxyController::class)
        ->where('path', '.*')
        ->name('servers.traefik.dashboard.assets');
    Route::get('servers/{server}/envoy/admin/{path?}', EnvoyAdminProxyController::class)
        ->where('path', '.*')
        ->name('servers.envoy.admin');
    Route::livewire('servers/{server}/configuration', WorkspaceConfiguration::class)->name('servers.configuration');
    Route::livewire('servers/{server}/errors', WorkspaceErrors::class)->name('servers.errors');
    Route::livewire('servers/{server}/notifications', WorkspaceNotifications::class)->name('servers.notifications');
    Route::livewire('servers/{server}/databases', WorkspaceDatabases::class)->name('servers.databases');
    Route::middleware('feature:workspace.caches')->group(function (): void {
        Route::livewire('servers/{server}/caches', WorkspaceCaches::class)->name('servers.caches');
    });
    // No feature middleware: the component renders the full workspace when
    // workspace.docker is on, or the coming-soon teaser when it is off but
    // workspace.docker_preview is on (else 404).
    Route::livewire('servers/{server}/docker', WorkspaceDocker::class)->name('servers.docker');
    Route::livewire('servers/{server}/docker-preview', WorkspaceDockerPreview::class)->name('servers.docker-preview');
    Route::livewire('servers/{server}/cron', WorkspaceCron::class)->name('servers.cron');
    Route::livewire('servers/{server}/workers', WorkspaceDaemons::class)->name('servers.workers');
    Route::livewire('servers/{server}/worker-pool', WorkspaceWorkerPool::class)->name('servers.worker-pool');
    Route::get('servers/{server}/queue-workers', function (Server $server) {
        return redirect()->route('servers.workers', array_merge(['server' => $server], request()->query()));
    })->name('servers.queue-workers');
    Route::middleware('feature:workspace.schedule')->group(function (): void {
        Route::livewire('servers/{server}/schedule', WorkspaceSchedule::class)->name('servers.schedule');
    });
    // The component renders the full workspace when workspace.backups is on,
    // or the coming-soon teaser when it is off but workspace.backups_preview
    // is on (else 404). The service.installed gate still applies.
    Route::livewire('servers/{server}/backups', WorkspaceBackups::class)->name('servers.backups');
    Route::livewire('servers/{server}/backups-preview', WorkspaceBackupsPreview::class)->name('servers.backups-preview');
    // Unified snapshots surface: server/VM images, cache (Redis) RDB, site
    // database snapshots, and volumes — one hub, distinct from logical Backups.
    Route::livewire('servers/{server}/snapshots', WorkspaceSnapshots::class)->name('servers.snapshots');
    // Back-compat: the surface was previously Redis-only at /redis-snapshots.
    // Redirect bookmarks/links to the renamed hub instead of 404ing.
    Route::get('servers/{server}/redis-snapshots', fn (string $server) => redirect()->route('servers.snapshots', $server))
        ->name('servers.redis-snapshots');
    Route::livewire('servers/{server}/firewall', WorkspaceFirewall::class)->name('servers.firewall');
    Route::livewire('servers/{server}/networking', WorkspaceNetworking::class)->name('servers.networking');
    Route::livewire('servers/{server}/load-balancers', WorkspaceLoadBalancers::class)->name('servers.load-balancers');
    Route::livewire('servers/{server}/ssh-keys', WorkspaceSshKeys::class)->name('servers.ssh-keys');
    Route::middleware('feature:workspace.system_users')->group(function (): void {
        Route::livewire('servers/{server}/system-users', WorkspaceSystemUsers::class)->name('servers.system-users');
    });
    // /run replaces both /recipes and /deploy. The merged page hosts
    // the saved-command list (CRUD + inline run), an ad-hoc command
    // runner, and the marketplace import path. Old URLs 404 by
    // design — clean break, no redirect shim.
    Route::middleware('feature:workspace.run')->group(function (): void {
        Route::livewire('servers/{server}/run', WorkspaceRun::class)->name('servers.run');
    });
    Route::livewire('servers/{server}/run-preview', WorkspaceRunPreview::class)->name('servers.run-preview');
    Route::middleware('feature:workspace.console')->group(function (): void {
        Route::livewire('servers/{server}/console', WorkspaceConsole::class)->name('servers.console');
    });
    Route::livewire('servers/{server}/console-preview', WorkspaceConsolePreview::class)->name('servers.console-preview');
    Route::livewire('servers/{server}/logs', WorkspaceLogs::class)->name('servers.logs');
    Route::livewire('servers/{server}/files', WorkspaceFiles::class)->name('servers.files');
    Route::get('servers/{server}/files/download', ServerWorkspaceFileDownloadController::class)->name('servers.files.download');
    Route::livewire('servers/{server}/files-preview', WorkspaceFilesPreview::class)->name('servers.files-preview');
    Route::get('log-shares/{token}', [LogViewerShareController::class, 'show'])->name('log-viewer-shares.show');
    Route::middleware('feature:workspace.cli')->group(function (): void {
        Route::livewire('servers/{server}/cli', WorkspaceCli::class)->name('servers.cli');
    });
    Route::livewire('servers/{server}/cli-preview', WorkspaceCliPreview::class)->name('servers.cli-preview');
    Route::livewire('servers/{server}/manage/{section?}', WorkspaceManage::class)->name('servers.manage');
    Route::livewire('servers/{server}/settings/{section?}', WorkspaceSettings::class)->name('servers.settings');

    Route::get('credentials', function () {
        $user = auth()->user();
        $organization = $user?->currentOrganization();

        app(Illuminate\Contracts\Auth\Access\Gate::class)->authorize('viewAny', ProviderCredential::class);
        abort_unless($organization, 404);

        $params = request()->query();
        $params['organization'] = $organization;

        return redirect()->route('organizations.credentials', $params);
    })->name('credentials.index');
    Route::get('credentials/oauth/digitalocean', [ProviderOAuthController::class, 'redirectDigitalOcean'])
        ->name('credentials.oauth.digitalocean.redirect');
    Route::get('credentials/oauth/digitalocean/callback', [ProviderOAuthController::class, 'callbackDigitalOcean'])
        ->name('credentials.oauth.digitalocean.callback');
});

require __DIR__.'/auth.php';
