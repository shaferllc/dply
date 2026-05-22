<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Serverless\LogsPanelTest;
use App\Livewire\Serverless\LogsPanel;
use App\Models\FunctionInvocation;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
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

    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $org->id,
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
/**
 * @param  array<string, mixed>  $attrs
 */
function invocation(Site $site, array $attrs): FunctionInvocation
{
    return FunctionInvocation::query()->create(array_merge([
        'site_id' => $site->id,
        'source' => FunctionInvocation::SOURCE_TICK,
        'task' => null,
        'method' => 'GET',
        'path' => '/',
        'status_code' => 200,
        'success' => true,
        'duration_ms' => 40,
        'cold' => false,
        'activation_id' => 'act-x',
        'log_lines' => [],
        'result_excerpt' => null,
        'created_at' => now(),
    ], $attrs));
}
test('activations tab lists operational invocations', function () {
    $site = functionSite();
    invocation($site, ['source' => 'tick', 'task' => 'schedule', 'path' => '/scheduled-run']);
    invocation($site, ['source' => 'test', 'path' => '/test-hit']);

    // A web row must NOT appear on the Activations tab.
    invocation($site, ['source' => 'web', 'path' => '/organic-only']);

    Livewire::actingAs($this->user)
        ->test(LogsPanel::class, ['site' => $site])
        ->assertSee('/scheduled-run')
        ->assertSee('/test-hit')
        ->assertDontSee('/organic-only');
});
test('visits tab lists web invocations', function () {
    $site = functionSite();
    invocation($site, ['source' => 'web', 'path' => '/organic-visit']);
    invocation($site, ['source' => 'tick', 'task' => 'queue', 'path' => '/queue-tick']);

    Livewire::actingAs($this->user)
        ->test(LogsPanel::class, ['site' => $site])
        ->call('setTab', 'visits')
        ->assertSee('/organic-visit')
        ->assertDontSee('/queue-tick');
});
test('runtime tab flattens log lines oldest first', function () {
    $site = functionSite();
    invocation($site, ['log_lines' => ['second line'], 'created_at' => now()]);
    invocation($site, ['log_lines' => ['first line'], 'created_at' => now()->subMinute()]);

    Livewire::actingAs($this->user)
        ->test(LogsPanel::class, ['site' => $site])
        ->call('setTab', 'runtime')
        ->assertSeeInOrder(['first line', 'second line']);
});
test('deploy tab lists function deployments', function () {
    $site = functionSite();
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'log_output' => 'Uploaded function bundle to OpenWhisk',
        'phase_results' => [
            'serverless' => [
                ['key' => 'build', 'label' => 'Build artifact', 'state' => 'done', 'ok' => true, 'duration_ms' => 1200],
            ],
        ],
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
    ]);

    Livewire::actingAs($this->user)
        ->test(LogsPanel::class, ['site' => $site])
        ->call('setTab', 'deploy')
        ->assertSee('Build artifact')
        ->assertSee('Uploaded function bundle to OpenWhisk');
});
test('set tab rejects unknown tabs', function () {
    $site = functionSite();

    Livewire::actingAs($this->user)
        ->test(LogsPanel::class, ['site' => $site])
        ->call('setTab', 'bogus')
        ->assertSet('tab', 'activations');
});
test('send test request invokes the function and records a test row', function () {
    Http::fake([
        'https://faas.example/api/v1/namespaces/_/actions/*' => Http::response([
            'activationId' => 'act-test-1',
            'duration' => 55,
            'annotations' => [],
            'logs' => ['production.INFO: hello from the test'],
            'response' => [
                'status' => 'success',
                'success' => true,
                'result' => ['statusCode' => 200, 'headers' => [], 'body' => 'OK'],
            ],
        ], 200),
    ]);

    $site = functionSite();

    Livewire::actingAs($this->user)
        ->test(LogsPanel::class, ['site' => $site])
        ->set('testPath', '/health')
        ->call('sendTestRequest')
        ->assertSee('production.INFO: hello from the test');

    $this->assertDatabaseHas('function_invocations', [
        'site_id' => $site->id,
        'source' => 'test',
        'activation_id' => 'act-test-1',
        'success' => true,
    ]);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/actions/laravel-demo')
        && data_get($request->data(), '__ow_headers.x-dply-source') === 'test');
});
