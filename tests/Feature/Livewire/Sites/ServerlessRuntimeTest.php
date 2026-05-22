<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites\ServerlessRuntimeTest;
use App\Livewire\Sites\Settings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $serverlessMeta
 * @return array{0: User, 1: Server, 2: Site}
 */
function functionSite(array $serverlessMeta = []): array
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
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => array_merge([
                'runtime' => 'nodejs:20',
                'entrypoint' => 'main',
                'action_name' => 'acme-api',
                'last_revision_id' => '0.0.4',
                'action_url' => 'https://faas-nyc1.doserverless.co/api/v1/web/fn-abc/default/acme-api',
            ], $serverlessMeta),
        ],
    ]);

    return [$user, $server, $site];
}
test('runtime tab renders the serverless control surface', function () {
    [$user, $server, $site] = functionSite();
    Http::fake();

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
        ->assertOk()
        ->assertSee('Execution profile')
        ->assertSee('Resource limits')
        ->assertSee('Concurrency')
        ->assertSee('Cold starts')
        // The VM runtime partial must NOT render for a function site.
        ->assertDontSee('Site processes')
        ->assertDontSee('Working directory');
});
test('it hydrates limit fields from stored config', function () {
    [$user, $server, $site] = functionSite([
        'limits' => ['memory' => 1024, 'timeout' => 90000, 'concurrency' => 6],
    ]);
    Http::fake();

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
        ->assertSet('serverless_memory', 1024)
        ->assertSet('serverless_timeout_ms', 90000)
        ->assertSet('serverless_concurrency', 6);
});
test('saving persists limits to site meta', function () {
    [$user, $server, $site] = functionSite();
    Http::fake();

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
        ->set('serverless_memory', 1024)
        ->set('serverless_timeout_ms', 120000)
        ->set('serverless_concurrency', 8)
        ->call('saveServerlessRuntime')
        ->assertHasNoErrors();

    expect($site->fresh()->serverlessLimits())->toBe([
        'memory' => 1024,
        'timeout' => 120000,
        'concurrency' => 8,
    ]);
});
test('it rejects an unsupported memory value', function () {
    [$user, $server, $site] = functionSite();
    Http::fake();

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
        ->set('serverless_memory', 999)
        ->call('saveServerlessRuntime')
        ->assertHasErrors('serverless_memory');
});
test('it rejects a timeout above the platform ceiling', function () {
    [$user, $server, $site] = functionSite();
    Http::fake();

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
        ->set('serverless_timeout_ms', 5_000_000)
        ->call('saveServerlessRuntime')
        ->assertHasErrors('serverless_timeout_ms');
});
test('it rejects concurrency above the ceiling', function () {
    [$user, $server, $site] = functionSite();
    Http::fake();

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
        ->set('serverless_concurrency', 999)
        ->call('saveServerlessRuntime')
        ->assertHasErrors('serverless_concurrency');
});
test('it flags a pending redeploy when saved limits differ from deployed', function () {
    [$user, $server, $site] = functionSite([
        'limits' => ['memory' => 1024, 'timeout' => 60000, 'concurrency' => 1],
        'deployed_limits' => ['memory' => 512, 'timeout' => 60000, 'concurrency' => 1],
    ]);
    Http::fake();

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
        ->assertSee('Redeploy now');
});
