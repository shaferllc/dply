<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\CaddyModulesInventoryTest;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\CaddyModulesManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function caddyModulesUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

test('loadCaddyModulesInventory resolves CaddyModulesManager service', function () {
    $user = caddyModulesUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'ssh_private_key' => 'test-key',
        'meta' => ['webserver' => 'caddy'],
    ]);

    $this->mock(CaddyModulesManager::class, function ($mock): void {
        $mock->shouldReceive('read')
            ->once()
            ->andReturn([
                'modules' => [
                    ['id' => 'http.handlers.file_server', 'namespace' => 'http.handlers', 'kind' => 'handlers'],
                ],
                'plugins' => [],
                'caddy_version' => '2.9.1',
                'custom_binary' => false,
                'unreadable' => false,
            ]);
        $mock->shouldReceive('availableCatalog')
            ->andReturn((array) config('caddy_modules.catalog', []));
    });

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('loadCaddyModulesInventory')
        ->assertSet('caddy_modules_loaded', true)
        ->assertSet('caddy_modules_caddy_version', '2.9.1')
        ->assertCount('caddy_modules_installed', 1);
});

test('openConfirmInstallCaddyModule shows install details modal', function () {
    Http::fake([
        'caddyserver.com/api/modules' => Http::response([
            'result' => [
                'dns.providers.cloudflare' => [[
                    'name' => 'dns.providers.cloudflare',
                    'docs' => 'Cloudflare DNS provider for ACME DNS-01.',
                    'package' => 'github.com/caddy-dns/cloudflare',
                    'repo' => 'https://github.com/caddy-dns/cloudflare',
                ]],
            ],
        ]),
    ]);

    $user = caddyModulesUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'ssh_private_key' => 'test-key',
        'meta' => ['webserver' => 'caddy'],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('openConfirmInstallCaddyModule', 'github.com/caddy-dns/cloudflare')
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'installCaddyModuleConfirmed')
        ->assertSet('confirmActionModalConfirmLabel', 'Add & rebuild')
        ->assertSet('confirmActionModalDetails.0.label', 'Plugin')
        ->assertSet('confirmActionModalDetails.1.value', 'github.com/caddy-dns/cloudflare');
});

test('queueCaddyModulesRebuild dispatches remote ssh job', function () {
    Queue::fake();

    $user = caddyModulesUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'ssh_private_key' => 'test-key',
        'meta' => [
            'webserver' => 'caddy',
            'caddy_modules' => [
                'plugins' => [
                    ['path' => 'github.com/caddy-dns/cloudflare'],
                ],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('queueCaddyModulesRebuild')
        ->assertSet('manageRemoteTaskName', 'manage-config:caddy-modules-rebuild')
        ->tap(fn ($component) => expect($component->instance()->caddyModulesBuildState()['active'])->toBeTrue());

    Queue::assertPushed(ServerManageRemoteSshJob::class, function (ServerManageRemoteSshJob $job): bool {
        return $job->taskName === 'manage-config:caddy-modules-rebuild';
    });
});
