<?php

use App\Http\Controllers\Credentials\ProviderOAuthController;
use App\Http\Controllers\DatabaseCredentialShareController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\LogViewerShareController;
use App\Http\Controllers\SiteDeployWebhookController;
use App\Jobs\RunSetupScriptJob;
use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\Backups\Databases as BackupsDatabases;
use App\Livewire\Backups\Files as BackupsFiles;
use App\Livewire\Billing\Invoices as BillingInvoices;
use App\Livewire\Billing\Show as BillingShow;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Invitations\Accept as InvitationsAccept;
use App\Livewire\Marketplace\Index as MarketplaceIndex;
use App\Livewire\Organizations\Create as OrganizationsCreate;
use App\Livewire\Organizations\Daemons as OrganizationsDaemons;
use App\Livewire\Organizations\Index as OrganizationsIndex;
use App\Livewire\Organizations\NotificationChannels as OrganizationsNotificationChannels;
use App\Livewire\Organizations\Show as OrganizationsShow;
use App\Livewire\Profile\DeleteAccount as ProfileDeleteAccount;
use App\Livewire\Profile\Edit as ProfileEdit;
use App\Livewire\Profile\Referrals as ProfileReferrals;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show as ProjectsShow;
use App\Livewire\Scripts\Create as ScriptsCreate;
use App\Livewire\Scripts\Edit as ScriptsEdit;
use App\Livewire\Scripts\Index as ScriptsIndex;
use App\Livewire\Scripts\Marketplace as ScriptsMarketplace;
use App\Livewire\Servers\Create as ServersCreate;
use App\Livewire\Servers\Index as ServersIndex;
use App\Livewire\Servers\ProvisionJourney as ServerProvisionJourney;
use App\Livewire\Servers\WorkspaceCron;
use App\Livewire\Servers\WorkspaceDaemons;
use App\Livewire\Servers\WorkspaceDatabases;
use App\Livewire\Servers\WorkspaceDeploy;
use App\Livewire\Servers\WorkspaceFirewall;
use App\Livewire\Servers\WorkspaceInsights;
use App\Livewire\Servers\WorkspaceLogs;
use App\Livewire\Servers\WorkspaceManage;
use App\Livewire\Servers\WorkspaceMonitor;
use App\Livewire\Servers\WorkspaceOverview;
use App\Livewire\Servers\WorkspacePhp;
use App\Livewire\Servers\WorkspaceRecipes;
use App\Livewire\Servers\WorkspaceServices;
use App\Livewire\Servers\WorkspaceSettings;
use App\Livewire\Servers\WorkspaceSites;
use App\Livewire\Servers\WorkspaceSshKeys;
use App\Livewire\Settings\ApiKeys as SettingsApiKeys;
use App\Livewire\Settings\BackupConfigurations as SettingsBackupConfigurations;
use App\Livewire\Settings\BulkNotificationAssignments;
use App\Livewire\Settings\Hub as SettingsHub;
use App\Livewire\Settings\NotificationChannels as SettingsNotificationChannels;
use App\Livewire\Settings\Security as SettingsSecurity;
use App\Livewire\Settings\SourceControl as SettingsSourceControl;
use App\Livewire\Settings\SshKeys as SettingsSshKeys;
use App\Livewire\Settings\WebserverTemplates as SettingsWebserverTemplates;
use App\Livewire\Sites\Create as SitesCreate;
use App\Livewire\Sites\Index as SitesIndex;
use App\Livewire\Sites\Show as SitesShow;
use App\Livewire\Sites\WorkspaceInsights as SitesWorkspaceInsights;
use App\Livewire\Status\PublicPage as StatusPublicPage;
use App\Livewire\StatusPages\Index as StatusPagesIndex;
use App\Livewire\StatusPages\Manage as StatusPagesManage;
use App\Livewire\Teams\NotificationChannels as TeamsNotificationChannels;
use App\Livewire\TwoFactor\Page as TwoFactorPage;
use App\Models\Server;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['web', 'auth']]);

Route::post('/hooks/sites/{site}/deploy', SiteDeployWebhookController::class)
    ->middleware(['throttle:site-webhook'])
    ->name('hooks.site.deploy');

Route::get('/', function () {
    return view('welcome');
});

Route::get('/pricing', function () {
    return view('pricing');
})->name('pricing');

Route::get('/features', function () {
    return view('features');
})->name('features');

Route::livewire('/status/{statusPage}', StatusPublicPage::class)
    ->middleware(['throttle:120,1'])
    ->name('status.public');

Route::get('/share/database-credentials/{token}', [DatabaseCredentialShareController::class, 'show'])
    ->middleware(['throttle:60,1'])
    ->name('database-credential-shares.show');

Route::middleware(['auth', 'verified', 'org'])->group(function () {
    Route::livewire('invitations/accept/{token}', InvitationsAccept::class)->name('invitations.accept');
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('/admin', AdminDashboard::class)
        ->middleware('can:viewPlatformAdmin')
        ->name('admin.dashboard');
    Route::livewire('/marketplace', MarketplaceIndex::class)->name('marketplace.index');

    Route::get('/docs', [DocsController::class, 'index'])->name('docs.index');
    Route::get('/docs/connect-provider', [DocsController::class, 'connectProvider'])->name('docs.connect-provider');
    Route::get('/docs/create-first-server', [DocsController::class, 'createFirstServer'])->name('docs.create-first-server');
    Route::get('/docs/org-roles-and-limits', [DocsController::class, 'orgRolesAndLimits'])->name('docs.org-roles-and-limits');
    Route::get('/docs/source-control', [DocsController::class, 'sourceControl'])->name('docs.source-control');

    Route::livewire('/settings', SettingsHub::class)->name('settings.index');

    Route::livewire('/profile', ProfileEdit::class)->name('profile.edit');
    Route::livewire('/profile/referrals', ProfileReferrals::class)->name('profile.referrals');
    Route::livewire('/profile/security', SettingsSecurity::class)->name('profile.security');
    Route::livewire('/profile/source-control', SettingsSourceControl::class)->name('profile.source-control');
    Route::livewire('/profile/ssh-keys', SettingsSshKeys::class)->name('profile.ssh-keys');
    Route::livewire('/profile/api-keys', SettingsApiKeys::class)->name('profile.api-keys');
    Route::livewire('/profile/backup-configurations', SettingsBackupConfigurations::class)->name('profile.backup-configurations');
    Route::livewire('/profile/notification-channels', SettingsNotificationChannels::class)->name('profile.notification-channels');
    Route::livewire('/profile/notification-channels/bulk-assign', BulkNotificationAssignments::class)->name('profile.notification-channels.bulk-assign');
    Route::livewire('/profile/delete-account', ProfileDeleteAccount::class)->name('profile.delete-account');

    Route::livewire('/profile/two-factor', TwoFactorPage::class)->name('two-factor.setup');

    Route::livewire('organizations', OrganizationsIndex::class)->name('organizations.index');
    Route::livewire('organizations/create', OrganizationsCreate::class)->name('organizations.create');
    Route::livewire('organizations/{organization}/daemons', OrganizationsDaemons::class)->name('organizations.daemons');
    Route::livewire('organizations/{organization}', OrganizationsShow::class)->name('organizations.show');
    Route::livewire('organizations/{organization}/notification-channels', OrganizationsNotificationChannels::class)->name('organizations.notification-channels');
    Route::livewire('organizations/{organization}/teams/{team}/notification-channels', TeamsNotificationChannels::class)->name('teams.notification-channels');
    Route::livewire('organizations/{organization}/billing', BillingShow::class)->name('billing.show');
    Route::livewire('organizations/{organization}/subscription', BillingShow::class)->name('subscription.show');
    Route::livewire('organizations/{organization}/invoices', BillingInvoices::class)->name('billing.invoices');
    Route::livewire('organizations/{organization}/webserver-templates', SettingsWebserverTemplates::class)->name('organizations.webserver-templates');

    Route::redirect('/backups', '/backups/databases')->name('backups.index');
    Route::livewire('/backups/databases', BackupsDatabases::class)->name('backups.databases');
    Route::livewire('/backups/files', BackupsFiles::class)->name('backups.files');

    Route::livewire('scripts', ScriptsIndex::class)->name('scripts.index');
    Route::livewire('scripts/marketplace', ScriptsMarketplace::class)->name('scripts.marketplace');
    Route::livewire('scripts/create', ScriptsCreate::class)->name('scripts.create');
    Route::livewire('scripts/{script}/edit', ScriptsEdit::class)->name('scripts.edit');

    Route::livewire('sites', SitesIndex::class)->name('sites.index');
    Route::livewire('projects', ProjectsIndex::class)->name('projects.index');
    Route::livewire('projects/{workspace}', ProjectsShow::class)->defaults('section', 'overview')->name('projects.show');
    Route::livewire('projects/{workspace}/overview', ProjectsShow::class)->defaults('section', 'overview')->name('projects.overview');
    Route::livewire('projects/{workspace}/resources', ProjectsShow::class)->defaults('section', 'resources')->name('projects.resources');
    Route::livewire('projects/{workspace}/access', ProjectsShow::class)->defaults('section', 'access')->name('projects.access');
    Route::livewire('projects/{workspace}/operations', ProjectsShow::class)->defaults('section', 'operations')->name('projects.operations');
    Route::livewire('projects/{workspace}/delivery', ProjectsShow::class)->defaults('section', 'delivery')->name('projects.delivery');
    Route::livewire('status-pages', StatusPagesIndex::class)->name('status-pages.index');
    Route::livewire('status-pages/{statusPage}', StatusPagesManage::class)->name('status-pages.manage');
    Route::livewire('servers', ServersIndex::class)->name('servers.index');
    Route::livewire('servers/create', ServersCreate::class)->name('servers.create');
    Route::get('servers/{server}', function (Server $server) {
        Gate::authorize('view', $server);

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
    Route::livewire('servers/{server}/sites/create', SitesCreate::class)->name('sites.create');
    Route::livewire('servers/{server}/sites/{site}/insights', SitesWorkspaceInsights::class)->name('sites.insights');
    Route::livewire('servers/{server}/sites/{site}', SitesShow::class)->name('sites.show');
    Route::livewire('servers/{server}/sites', WorkspaceSites::class)->name('servers.sites');
    Route::livewire('servers/{server}/insights', WorkspaceInsights::class)->name('servers.insights');
    Route::livewire('servers/{server}/overview', WorkspaceOverview::class)->name('servers.overview');
    Route::livewire('servers/{server}/monitor', WorkspaceMonitor::class)->name('servers.monitor');
    Route::livewire('servers/{server}/services', WorkspaceServices::class)->name('servers.services');
    Route::livewire('servers/{server}/php', WorkspacePhp::class)->name('servers.php');
    Route::livewire('servers/{server}/databases', WorkspaceDatabases::class)->name('servers.databases');
    Route::livewire('servers/{server}/cron', WorkspaceCron::class)->name('servers.cron');
    Route::livewire('servers/{server}/daemons', WorkspaceDaemons::class)->name('servers.daemons');
    Route::livewire('servers/{server}/firewall', WorkspaceFirewall::class)->name('servers.firewall');
    Route::livewire('servers/{server}/ssh-keys', WorkspaceSshKeys::class)->name('servers.ssh-keys');
    Route::livewire('servers/{server}/recipes', WorkspaceRecipes::class)->name('servers.recipes');
    Route::livewire('servers/{server}/deploy', WorkspaceDeploy::class)->name('servers.deploy');
    Route::livewire('servers/{server}/logs', WorkspaceLogs::class)->name('servers.logs');
    Route::get('log-shares/{token}', [LogViewerShareController::class, 'show'])->name('log-viewer-shares.show');
    Route::livewire('servers/{server}/manage', WorkspaceManage::class)->name('servers.manage');
    Route::livewire('servers/{server}/settings/{section?}', WorkspaceSettings::class)->name('servers.settings');

    Route::livewire('credentials', CredentialsIndex::class)->name('credentials.index');
    Route::get('credentials/oauth/digitalocean', [ProviderOAuthController::class, 'redirectDigitalOcean'])
        ->name('credentials.oauth.digitalocean.redirect');
    Route::get('credentials/oauth/digitalocean/callback', [ProviderOAuthController::class, 'callbackDigitalOcean'])
        ->name('credentials.oauth.digitalocean.callback');
});

require __DIR__.'/auth.php';
