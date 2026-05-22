<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Serverless\PlatformPanelTest;
use App\Livewire\Serverless\PlatformPanel;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function functionSite(): Site
{
    $this->user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $this->user->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'tok-123'],
    ]);

    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => [
                'api_host' => 'https://faas.example',
                'access_key' => 'id:secret',
                'namespace' => 'fn-test',
            ],
        ],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $this->user->id,
        'meta' => ['serverless' => ['action_name' => 'laravel-demo']],
    ]);
}
/** Fake every OpenWhisk endpoint the panel touches. */
function fakeOpenWhisk(): void
{
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/actions/laravel-demo') && str_contains($url, 'blocking=true')) {
            return Http::response([
                'activationId' => 'act-1',
                'duration' => 12,
                'annotations' => [],
                'logs' => ['production.INFO: console hit'],
                'response' => [
                    'status' => 'success', 'success' => true,
                    'result' => ['statusCode' => 200, 'headers' => [], 'body' => 'console-ok'],
                ],
            ], 200);
        }
        if (str_contains($url, '/actions/laravel-demo')) {
            return Http::response([
                'name' => 'laravel-demo', 'version' => '0.0.11', 'publish' => false,
                'exec' => ['kind' => 'php:8.4', 'main' => 'main', 'binary' => true],
                'limits' => ['memory' => 512, 'timeout' => 60000, 'concurrency' => 1, 'logs' => 16],
                'annotations' => [['key' => 'web-export', 'value' => true]],
            ], 200);
        }
        if (str_contains($url, '/actions')) {
            return Http::response([['name' => 'laravel-demo']], 200);
        }
        if (str_contains($url, '/triggers')) {
            return Http::response([['name' => 'nightly', 'parameters' => []]], 200);
        }
        if (str_contains($url, '/rules')) {
            return Http::response([[
                'name' => 'nightly-rule', 'status' => 'active',
                'trigger' => ['name' => 'nightly'], 'action' => ['name' => 'laravel-demo'],
            ]], 200);
        }

        return Http::response([], 200);
    });
}
test('inspector renders the live action doc', function () {
    fakeOpenWhisk();
    $site = functionSite();

    Livewire::actingAs($this->user)
        ->test(PlatformPanel::class, ['site' => $site])
        ->assertSee('laravel-demo')
        ->assertSee('php:8.4')
        ->assertSee('Namespace');
});
test('triggers tab lists and creates a trigger', function () {
    fakeOpenWhisk();
    $site = functionSite();

    Livewire::actingAs($this->user)
        ->test(PlatformPanel::class, ['site' => $site])
        ->call('setTab', 'triggers')
        ->assertSee('nightly')
        ->assertSee('nightly-rule')
        ->set('newTriggerName', 'fresh-trigger')
        ->call('createTrigger')
        ->assertHasNoErrors();

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/triggers/fresh-trigger'));
});
test('adding a schedule preset creates a do scheduled trigger', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'api.digitalocean.com')) {
            return Http::response(['trigger' => ['name' => 'dply-hourly']], 200);
        }

        return Http::response([], 200);
    });
    $site = functionSite();

    Livewire::actingAs($this->user)
        ->test(PlatformPanel::class, ['site' => $site])
        ->call('setTab', 'triggers')
        ->call('addSchedulePreset', 'hourly');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.digitalocean.com')
        && $request->method() === 'POST'
        && str_contains($request->url(), '/functions/namespaces/fn-test/triggers')
        && data_get($request->data(), 'scheduled_details.cron') === '0 * * * *');
});
test('custom schedule rejects a bad cron', function () {
    Http::fake();
    $site = functionSite();

    Livewire::actingAs($this->user)
        ->test(PlatformPanel::class, ['site' => $site])
        ->call('setTab', 'triggers')
        ->set('newScheduleCron', 'not-a-cron')
        ->call('addCustomSchedule')
        ->assertHasErrors('newScheduleCron');
});
test('create trigger rejects invalid json params', function () {
    fakeOpenWhisk();
    $site = functionSite();

    Livewire::actingAs($this->user)
        ->test(PlatformPanel::class, ['site' => $site])
        ->call('setTab', 'triggers')
        ->set('newTriggerName', 'bad')
        ->set('newTriggerParams', 'not json')
        ->call('createTrigger')
        ->assertHasErrors('newTriggerParams');
});
test('delete action calls openwhisk', function () {
    fakeOpenWhisk();
    $site = functionSite();

    Livewire::actingAs($this->user)
        ->test(PlatformPanel::class, ['site' => $site])
        ->call('deleteAction');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/actions/laravel-demo'));
});
test('console invokes the function', function () {
    fakeOpenWhisk();
    $site = functionSite();

    Livewire::actingAs($this->user)
        ->test(PlatformPanel::class, ['site' => $site])
        ->call('setTab', 'console')
        ->set('consolePath', '/health')
        ->call('sendConsole')
        ->assertSet('consoleResult.success', true)
        ->assertSee('console-ok');

    $this->assertDatabaseHas('function_invocations', [
        'site_id' => $site->id,
        'source' => 'test',
    ]);
});
test('set tab rejects unknown tabs', function () {
    fakeOpenWhisk();
    $site = functionSite();

    Livewire::actingAs($this->user)
        ->test(PlatformPanel::class, ['site' => $site])
        ->call('setTab', 'bogus')
        ->assertSet('tab', 'inspector');
});
