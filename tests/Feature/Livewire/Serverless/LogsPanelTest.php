<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Serverless\LogsPanelTest;

use App\Modules\Serverless\Livewire\LogsPanel;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('surface.serverless');

/** @return array{0: User, 1: Site} */
function functionSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
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

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['serverless' => ['action_name' => 'laravel-demo']],
    ]);

    return [$user, $site];
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
    [$user, $site] = functionSite();
    invocation($site, ['source' => 'tick', 'task' => 'schedule', 'path' => '/scheduled-run']);
    invocation($site, ['source' => 'test', 'path' => '/test-hit']);

    // A web row must NOT appear on the Activations tab.
    invocation($site, ['source' => 'web', 'path' => '/organic-only']);

    Livewire::actingAs($user)
        ->test(LogsPanel::class, ['site' => $site])
        ->assertSee('/scheduled-run')
        ->assertSee('/test-hit')
        ->assertDontSee('/organic-only');
});
test('visits tab lists web invocations', function () {
    [$user, $site] = functionSite();
    invocation($site, ['source' => 'web', 'path' => '/organic-visit']);
    invocation($site, ['source' => 'tick', 'task' => 'queue', 'path' => '/queue-tick']);

    Livewire::actingAs($user)
        ->test(LogsPanel::class, ['site' => $site])
        ->call('setTab', 'visits')
        ->assertSee('/organic-visit')
        ->assertDontSee('/queue-tick');
});
test('runtime tab flattens log lines oldest first', function () {
    [$user, $site] = functionSite();
    invocation($site, ['log_lines' => ['second line'], 'created_at' => now()]);
    invocation($site, ['log_lines' => ['first line'], 'created_at' => now()->subMinute()]);

    Livewire::actingAs($user)
        ->test(LogsPanel::class, ['site' => $site])
        ->call('setTab', 'runtime')
        ->assertSeeInOrder(['first line', 'second line']);
});
test('runtime tab filters by level, carrying the level onto trace lines', function () {
    [$user, $site] = functionSite();
    invocation($site, ['log_lines' => [
        '[2026-08-21 12:00:00] production.ERROR: Boom',
        '#0 /app/Http/Kernel.php(12): boom()',
        '[2026-08-21 12:00:01] production.INFO: Everything is fine',
        // DigitalOcean prefixes activation logs with its own timestamp+stream.
        '2026-08-21T12:00:02.000Z stdout: [2026-08-21 12:00:02] production.WARNING: Careful',
    ]]);

    Livewire::actingAs($user)
        ->test(LogsPanel::class, ['site' => $site])
        ->call('setTab', 'runtime')
        ->set('runtimeLevel', 'error')
        ->assertSee('Boom')
        ->assertSee('/app/Http/Kernel.php(12)')
        ->assertDontSee('Everything is fine')
        ->assertDontSee('Careful')
        ->set('runtimeLevel', 'warning')
        ->assertSee('Careful')
        ->assertDontSee('Boom');
});
test('runtime tab searches output case-insensitively', function () {
    [$user, $site] = functionSite();
    invocation($site, ['log_lines' => ['connection refused', 'cache warmed']]);

    Livewire::actingAs($user)
        ->test(LogsPanel::class, ['site' => $site])
        ->call('setTab', 'runtime')
        ->set('runtimeSearch', 'REFUSED')
        ->assertSee('connection refused')
        ->assertDontSee('cache warmed');
});
test('runtime tab bounds output by the selected time range', function () {
    [$user, $site] = functionSite();
    invocation($site, ['log_lines' => ['ancient line'], 'created_at' => now()->subDays(3)]);

    Livewire::actingAs($user)
        ->test(LogsPanel::class, ['site' => $site])
        ->call('setTab', 'runtime')
        ->assertSee('ancient line')
        ->set('runtimeRange', '15m')
        ->assertDontSee('ancient line')
        ->set('runtimeRange', '7d')
        ->assertSee('ancient line')
        ->call('resetRuntimeFilters')
        ->assertSee('ancient line');
});
test('deploy tab lists function deployments', function () {
    [$user, $site] = functionSite();
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

    Livewire::actingAs($user)
        ->test(LogsPanel::class, ['site' => $site])
        ->call('setTab', 'deploy')
        ->assertSee('Build artifact')
        ->assertSee('Uploaded function bundle to OpenWhisk');
});
test('set tab rejects unknown tabs', function () {
    [$user, $site] = functionSite();

    Livewire::actingAs($user)
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

    [$user, $site] = functionSite();

    Livewire::actingAs($user)
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
