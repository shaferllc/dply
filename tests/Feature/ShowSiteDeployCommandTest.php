<?php

declare(strict_types=1);

namespace Tests\Feature\ShowSiteDeployCommandTest;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('renders phase tree with step status', function () {
    // phase_results[$phase] is a flat list of steps (matches what
    // DeploymentRunner produces when it calls recordPhaseResults).
    $deployment = seedDeployment(SiteDeployment::STATUS_SUCCESS, [
        'build' => [
            ['step_type' => 'install', 'command' => 'npm ci', 'ok' => true, 'duration_ms' => 1234],
            ['step_type' => 'build', 'command' => 'npm run build', 'ok' => true, 'duration_ms' => 5678],
        ],
    ]);

    $exit = Artisan::call('dply:site:show-deploy', ['id' => $deployment->id]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('build', $output);
    $this->assertStringContainsString('install', $output);
    $this->assertStringContainsString('npm run build', $output);
    $this->assertStringContainsString('5678ms', $output);
});
test('phase filter limits render', function () {
    $deployment = seedDeployment(SiteDeployment::STATUS_SUCCESS, [
        'build' => [['step_type' => 'b1', 'command' => 'BUILD_CMD', 'ok' => true]],
        'release' => [['step_type' => 'r1', 'command' => 'RELEASE_CMD', 'ok' => true]],
    ]);

    Artisan::call('dply:site:show-deploy', [
        'id' => $deployment->id,
        '--phase' => 'release',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect(array_keys($decoded['phase_results']))->toBe(['release']);
});
test('output flag includes captured text', function () {
    $deployment = seedDeployment(SiteDeployment::STATUS_FAILED, [
        'build' => [
            ['step_type' => 'install', 'command' => 'npm ci', 'ok' => false, 'output' => 'error: missing lockfile'],
        ],
    ]);

    $exit = Artisan::call('dply:site:show-deploy', [
        'id' => $deployment->id,
        '--output' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('error: missing lockfile', $output);
});
test('output flag omitted does not include step output', function () {
    $deployment = seedDeployment(SiteDeployment::STATUS_SUCCESS, [
        'build' => [
            ['step_type' => 'install', 'command' => 'npm ci', 'ok' => true, 'output' => 'INTERNAL_DEBUG_TRACE'],
        ],
    ]);

    Artisan::call('dply:site:show-deploy', ['id' => $deployment->id]);
    $output = Artisan::output();

    $this->assertStringNotContainsString('INTERNAL_DEBUG_TRACE', $output);
});
test('failed deployment exits non zero', function () {
    $deployment = seedDeployment(SiteDeployment::STATUS_FAILED, [
        'build' => [],
    ]);

    $exit = Artisan::call('dply:site:show-deploy', ['id' => $deployment->id]);

    expect($exit)->toBe(1);
});
test('no phase results renders friendly message', function () {
    $deployment = seedDeployment(SiteDeployment::STATUS_SUCCESS, []);

    Artisan::call('dply:site:show-deploy', ['id' => $deployment->id]);
    $output = Artisan::output();

    $this->assertStringContainsString('No phase results', $output);
});
test('command fails when deployment not found', function () {
    $exit = Artisan::call('dply:site:show-deploy', ['id' => 'missing']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Deployment not found', $output);
});
/**
 * @param  array<string, mixed>  $phaseResults
 */
function seedDeployment(string $status, array $phaseResults): SiteDeployment
{
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);

    return SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'status' => $status,
        'trigger' => 'manual',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'phase_results' => $phaseResults,
    ]);
}
