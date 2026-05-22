<?php

declare(strict_types=1);

namespace Tests\Feature\FailedDeploysFleetCommandTest;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('lists sites with failed latest deploy', function () {
    $server = Server::factory()->create();
    $broken = Site::factory()->create(['server_id' => $server->id, 'name' => 'broken-app']);
    $healthy = Site::factory()->create(['server_id' => $server->id, 'name' => 'healthy-app']);

    // Broken: only failed deploy.
    seedDeploy($broken, SiteDeployment::STATUS_FAILED, now()->subHour());

    // Healthy: success after a previous failure.
    seedDeploy($healthy, SiteDeployment::STATUS_FAILED, now()->subDay());
    seedDeploy($healthy, SiteDeployment::STATUS_SUCCESS, now()->subHour());

    $exit = Artisan::call('dply:fleet:failed-deploys', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1);
    expect($decoded['count'])->toBe(1);
    expect($decoded['sites'][0]['site_name'])->toBe('broken-app');
});
test('skips running deploys by default', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);

    // Latest is "running" — but the previous failed.
    seedDeploy($site, SiteDeployment::STATUS_FAILED, now()->subHour());
    seedDeploy($site, SiteDeployment::STATUS_RUNNING, now()->subMinutes(2));

    Artisan::call('dply:fleet:failed-deploys', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    // Running is skipped, so latest settled = failed → counted.
    expect($decoded['count'])->toBe(1);
});
test('include running treats running as latest', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    seedDeploy($site, SiteDeployment::STATUS_FAILED, now()->subHour());
    seedDeploy($site, SiteDeployment::STATUS_RUNNING, now()->subMinutes(2));

    Artisan::call('dply:fleet:failed-deploys', [
        '--include-running' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    // Latest is now running, not failed.
    expect($decoded['count'])->toBe(0);
});
test('zero failures returns success exit code', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now()->subHour());

    $exit = Artisan::call('dply:fleet:failed-deploys');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No sites', $output);
});
test('orders most recently failed first', function () {
    $server = Server::factory()->create();
    $a = Site::factory()->create(['server_id' => $server->id, 'name' => 'older-failure']);
    $b = Site::factory()->create(['server_id' => $server->id, 'name' => 'recent-failure']);
    seedDeploy($a, SiteDeployment::STATUS_FAILED, now()->subDays(7));
    seedDeploy($b, SiteDeployment::STATUS_FAILED, now()->subHour());

    Artisan::call('dply:fleet:failed-deploys', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['sites'][0]['site_name'])->toBe('recent-failure');
    expect($decoded['sites'][1]['site_name'])->toBe('older-failure');
});
test('includes drill in hint in human output', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    seedDeploy($site, SiteDeployment::STATUS_FAILED, now()->subHour());

    Artisan::call('dply:fleet:failed-deploys');
    $output = Artisan::output();

    $this->assertStringContainsString('dply:site:show-deploy', $output);
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
