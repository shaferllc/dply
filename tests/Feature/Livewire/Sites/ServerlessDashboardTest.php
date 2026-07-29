<?php

namespace Tests\Feature\Livewire\Sites\ServerlessDashboardTest;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function functionSite(array $serverlessMeta): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'git_repository_url' => 'acme/api',
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => $serverlessMeta,
        ],
    ]);

    return [$user, $server, $site];
}

test('general section shows the invocation url for a deployed function', function () {
    [$user, $server, $site] = functionSite([
        'runtime' => 'nodejs:20',
        'action_url' => 'https://faas-nyc1.doserverless.co/api/v1/web/fn-abc/default/api',
        'last_revision_id' => '7',
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertOk()
        ->assertSee('Function URL')
        ->assertSee('Direct:')
        ->assertSee('faas-nyc1.doserverless.co')
        ->assertSee('nodejs:20')
        ->assertSee('Manage deploys');
});

test('pre deploy function shows a pending url notice', function () {
    [$user, $server, $site] = functionSite(['runtime' => 'nodejs:20']);
    $site->update(['status' => Site::STATUS_FUNCTIONS_CONFIGURED]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site->fresh(), 'section' => 'general'])
        ->assertOk()
        ->assertSee('Live once the first deploy completes.')
        ->assertSee('Deploy function');
});

test('deploy redeploy button dispatches a deployment and redirects to the journey', function () {
    Bus::fake();
    [$user, $server, $site] = functionSite([
        'runtime' => 'php:8.4',
        'action_url' => 'https://faas-nyc1.doserverless.co/api/v1/web/fn-abc/default/api',
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('redeployServerlessFunction')
        ->assertRedirect(route('serverless.journey', ['server' => $server, 'site' => $site]));

    Bus::assertDispatched(RunSiteDeploymentJob::class);
});
