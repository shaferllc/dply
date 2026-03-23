<?php

use App\Http\Controllers\DocsController;
use App\Livewire\Billing\Show as BillingShow;
use App\Livewire\Invitations\Accept as InvitationsAccept;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Organizations\Create as OrganizationsCreate;
use App\Livewire\Organizations\Index as OrganizationsIndex;
use App\Livewire\Organizations\Show as OrganizationsShow;
use App\Livewire\Profile\Edit as ProfileEdit;
use App\Livewire\Servers\Create as ServersCreate;
use App\Livewire\Servers\Index as ServersIndex;
use App\Livewire\Servers\Show as ServersShow;
use App\Livewire\Sites\Create as SitesCreate;
use App\Livewire\Sites\Index as SitesIndex;
use App\Livewire\Sites\Show as SitesShow;
use App\Livewire\TwoFactor\Page as TwoFactorPage;
use App\Http\Controllers\SiteDeployWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/hooks/sites/{site}/deploy', SiteDeployWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('hooks.site.deploy');

Route::get('/', function () {
    return view('welcome');
});

Route::get('/pricing', function () {
    return view('pricing');
})->name('pricing');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('invitations/accept/{token}', InvitationsAccept::class)->name('invitations.accept');
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');

    Route::get('/docs', [DocsController::class, 'index'])->name('docs.index');
    Route::get('/docs/connect-provider', [DocsController::class, 'connectProvider'])->name('docs.connect-provider');
    Route::get('/docs/create-first-server', [DocsController::class, 'createFirstServer'])->name('docs.create-first-server');

    Route::livewire('/profile', ProfileEdit::class)->name('profile.edit');

    Route::livewire('/profile/two-factor', TwoFactorPage::class)->name('two-factor.setup');

    Route::livewire('organizations', OrganizationsIndex::class)->name('organizations.index');
    Route::livewire('organizations/create', OrganizationsCreate::class)->name('organizations.create');
    Route::livewire('organizations/{organization}', OrganizationsShow::class)->name('organizations.show');
    Route::livewire('organizations/{organization}/billing', BillingShow::class)->name('billing.show');

    Route::middleware('org')->group(function () {
        Route::livewire('sites', SitesIndex::class)->name('sites.index');
        Route::livewire('servers', ServersIndex::class)->name('servers.index');
        Route::livewire('servers/create', ServersCreate::class)->name('servers.create');
        Route::livewire('servers/{server}', ServersShow::class)->name('servers.show');
        Route::livewire('servers/{server}/sites/create', SitesCreate::class)->name('sites.create');
        Route::livewire('servers/{server}/sites/{site}', SitesShow::class)->name('sites.show');

        Route::livewire('credentials', CredentialsIndex::class)->name('credentials.index');
    });
});

require __DIR__.'/auth.php';
