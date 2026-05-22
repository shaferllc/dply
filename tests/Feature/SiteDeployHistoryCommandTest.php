<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDeployHistoryCommandTest;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('command lists recent deployments with phase summary', function () {
    $site = makeSiteWithDeployments();

    $exit = Artisan::call('dply:site:deploy-history', ['site' => $site->slug]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Recent deployments', $output);
    $this->assertStringContainsString('success', $output);
    $this->assertStringContainsString('build(1)', $output);
    $this->assertStringContainsString('release(1)', $output);
});
test('command emits json with phase breakdown', function () {
    $site = makeSiteWithDeployments();

    $exit = Artisan::call('dply:site:deploy-history', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded['site_id'])->toBe($site->id);
    expect($decoded['count'])->toBeGreaterThanOrEqual(1);
    expect($decoded['deployments'][0]['phases'])->toHaveKey('build');
    expect($decoded['deployments'][0]['phases']['build']['ok'])->toBeTrue();
});
test('command returns zero with message when no deployments', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'fresh-site',
    ]);

    $exit = Artisan::call('dply:site:deploy-history', ['site' => 'fresh-site']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No deployments recorded', $output);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:deploy-history', ['site' => 'no-such']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
test('limit option caps returned rows', function () {
    $site = makeSiteWithDeployments(count: 5);

    $exit = Artisan::call('dply:site:deploy-history', [
        'site' => $site->slug,
        '--limit' => 2,
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['count'])->toBe(2);
});
function makeSiteWithDeployments(int $count = 1): Site
{
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
    ]);
    $site->refresh();

    for ($i = 0; $i < $count; $i++) {
        $deployment = SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'dep-'.$i.'-'.uniqid(),
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now()->subMinutes($i),
            'finished_at' => now()->subSeconds($i),
        ]);
        $deployment->recordPhaseResults('build', [
            ['step_id' => 'b'.$i, 'command' => 'composer install', 'ok' => true, 'output' => '', 'duration_ms' => 4000],
        ]);
        $deployment->recordPhaseResults('release', [
            ['step_id' => 'r'.$i, 'command' => 'php artisan migrate --force', 'ok' => true, 'output' => '', 'duration_ms' => 800],
        ]);
    }

    return $site;
}
