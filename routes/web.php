<?php

use App\Http\Controllers\Credentials\ProviderOAuthController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\SiteDeployWebhookController;
use App\Livewire\Backups\Databases as BackupsDatabases;
use App\Livewire\Backups\Files as BackupsFiles;
use App\Livewire\Billing\Invoices as BillingInvoices;
use App\Livewire\Billing\Show as BillingShow;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Invitations\Accept as InvitationsAccept;
use App\Livewire\Marketplace\Index as MarketplaceIndex;
use App\Livewire\Organizations\Create as OrganizationsCreate;
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
use App\Livewire\Servers\Show as ServersShow;
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
use App\Livewire\Status\PublicPage as StatusPublicPage;
use App\Livewire\StatusPages\Index as StatusPagesIndex;
use App\Livewire\StatusPages\Manage as StatusPagesManage;
use App\Livewire\Teams\NotificationChannels as TeamsNotificationChannels;
use App\Livewire\TwoFactor\Page as TwoFactorPage;
use Illuminate\Support\Facades\Broadcast;
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

Route::middleware(['auth', 'verified', 'org'])->group(function () {
    Route::livewire('invitations/accept/{token}', InvitationsAccept::class)->name('invitations.accept');
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
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
    Route::livewire('projects/{workspace}', ProjectsShow::class)->name('projects.show');
    Route::livewire('status-pages', StatusPagesIndex::class)->name('status-pages.index');
    Route::livewire('status-pages/{statusPage}', StatusPagesManage::class)->name('status-pages.manage');
    Route::livewire('servers', ServersIndex::class)->name('servers.index');
    Route::livewire('servers/create', ServersCreate::class)->name('servers.create');
    Route::livewire('servers/{server}', ServersShow::class)->name('servers.show');
    Route::livewire('servers/{server}/sites/create', SitesCreate::class)->name('sites.create');
    Route::livewire('servers/{server}/sites/{site}', SitesShow::class)->name('sites.show');

    Route::livewire('credentials', CredentialsIndex::class)->name('credentials.index');
    Route::get('credentials/oauth/digitalocean', [ProviderOAuthController::class, 'redirectDigitalOcean'])
        ->name('credentials.oauth.digitalocean.redirect');
    Route::get('credentials/oauth/digitalocean/callback', [ProviderOAuthController::class, 'callbackDigitalOcean'])
        ->name('credentials.oauth.digitalocean.callback');
});

require __DIR__.'/auth.php';
