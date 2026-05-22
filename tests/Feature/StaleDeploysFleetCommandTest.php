<?php

declare(strict_types=1);

namespace Tests\Feature\StaleDeploysFleetCommandTest;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('lists sites with old deploys', function () {
    $server = Server::factory()->create();
    $stale = Site::factory()->create(['server_id' => $server->id, 'name' => 'old-app']);
    $fresh = Site::factory()->create(['server_id' => $server->id, 'name' => 'fresh-app']);
    seedDeployment($stale, now()->subDays(60));
    seedDeployment($fresh, now()->subDays(5));

    Artisan::call('dply:fleet:stale-deploys', [
        '--days' => 30,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(1);
    expect($decoded['sites'][0]['site_name'])->toBe('old-app');
    expect($decoded['sites'][0]['age_days'])->toBeGreaterThanOrEqual(60);
});
test('excludes never deployed by default', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id, 'name' => 'never-deployed']);

    Artisan::call('dply:fleet:stale-deploys', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(0);
});
test('include never flag adds undeployed sites', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id, 'name' => 'never-deployed']);

    Artisan::call('dply:fleet:stale-deploys', [
        '--include-never' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(1);
    expect($decoded['sites'][0]['last_deploy_at'])->toBeNull();
    expect($decoded['sites'][0]['age_days'])->toBeNull();
});
test('only successful deploys count', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);

    // Failed deploy 5 days ago, no successful ever — should be treated as never-deployed.
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'status' => SiteDeployment::STATUS_FAILED,
        'trigger' => 'manual',
        'started_at' => now()->subDays(5),
        'finished_at' => now()->subDays(5),
    ]);

    Artisan::call('dply:fleet:stale-deploys', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(0);

    // With --include-never, this site should appear because it has
    // no successful deploys.
    Artisan::call('dply:fleet:stale-deploys', [
        '--include-never' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);
    expect($decoded['count'])->toBe(1);
});
test('oldest first ordering', function () {
    $server = Server::factory()->create();
    $a = Site::factory()->create(['server_id' => $server->id, 'name' => 'older-app']);
    $b = Site::factory()->create(['server_id' => $server->id, 'name' => 'newer-app']);
    seedDeployment($a, now()->subDays(120));
    seedDeployment($b, now()->subDays(45));

    Artisan::call('dply:fleet:stale-deploys', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['sites'][0]['site_name'])->toBe('older-app');
    expect($decoded['sites'][1]['site_name'])->toBe('newer-app');
});
test('human friendly message when nothing stale', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    seedDeployment($site, now()->subDays(2));

    $exit = Artisan::call('dply:fleet:stale-deploys');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No stale sites', $output);
});
function seedDeployment(Site $site, \DateTimeInterface $finishedAt): SiteDeployment
{
    return SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'trigger' => 'manual',
        'started_at' => $finishedAt,
        'finished_at' => $finishedAt,
    ]);
}
