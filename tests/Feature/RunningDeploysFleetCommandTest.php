<?php

declare(strict_types=1);

namespace Tests\Feature\RunningDeploysFleetCommandTest;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('lists currently running deploys', function () {
    $server = Server::factory()->create();
    $a = Site::factory()->create(['server_id' => $server->id, 'name' => 'site-a']);
    $b = Site::factory()->create(['server_id' => $server->id, 'name' => 'site-b']);
    seedDeploy($a, SiteDeployment::STATUS_RUNNING, now()->subMinutes(20));
    seedDeploy($b, SiteDeployment::STATUS_RUNNING, now()->subMinutes(2));
    seedDeploy($a, SiteDeployment::STATUS_SUCCESS, now()->subHours(2));

    // ignored
    Artisan::call('dply:fleet:running-deploys', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(2);

    // Sorted by started_at ascending — so site-a (older) comes first.
    expect($decoded['deployments'][0]['site_name'])->toBe('site-a');
    expect($decoded['deployments'][1]['site_name'])->toBe('site-b');
});
test('excludes settled deploys', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now()->subMinutes(1));
    seedDeploy($site, SiteDeployment::STATUS_FAILED, now()->subMinutes(2));

    Artisan::call('dply:fleet:running-deploys', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(0);
});
test('older than filter', function () {
    $server = Server::factory()->create();
    $a = Site::factory()->create(['server_id' => $server->id, 'name' => 'old-running']);
    $b = Site::factory()->create(['server_id' => $server->id, 'name' => 'fresh-running']);
    seedDeploy($a, SiteDeployment::STATUS_RUNNING, now()->subMinutes(30));
    seedDeploy($b, SiteDeployment::STATUS_RUNNING, now()->subMinutes(2));

    Artisan::call('dply:fleet:running-deploys', [
        '--older-than' => 15,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(1);
    expect($decoded['deployments'][0]['site_name'])->toBe('old-running');
});
test('includes deploy id for drill in', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    $deploy = seedDeploy($site, SiteDeployment::STATUS_RUNNING, now()->subMinutes(5));

    Artisan::call('dply:fleet:running-deploys', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['deployments'][0]['deployment_id'])->toBe($deploy->id);
});
test('friendly message when nothing running', function () {
    $exit = Artisan::call('dply:fleet:running-deploys');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No deploys are currently running', $output);
});
function seedDeploy(Site $site, string $status, \DateTimeInterface $startedAt): SiteDeployment
{
    return SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'status' => $status,
        'trigger' => 'manual',
        'started_at' => $startedAt,
        'finished_at' => $status === SiteDeployment::STATUS_RUNNING ? null : $startedAt,
    ]);
}
