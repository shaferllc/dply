<?php

declare(strict_types=1);

namespace Tests\Feature\AbortSiteDeployCommandTest;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('aborts latest running deployment', function () {
    $site = makeSite();
    $deployment = seedDeployment($site, SiteDeployment::STATUS_RUNNING, now()->subHour());

    $exit = Artisan::call('dply:site:abort-deploy', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($decoded['deployment_id'])->toBe($deployment->id);
    expect($decoded['new_status'])->toBe('failed');
    $deployment->refresh();
    expect($deployment->status)->toBe('failed');
    expect($deployment->finished_at)->not->toBeNull();
});
test('aborts specific deployment by id', function () {
    $site = makeSite();
    $older = seedDeployment($site, SiteDeployment::STATUS_RUNNING, now()->subDay());
    $newer = seedDeployment($site, SiteDeployment::STATUS_RUNNING, now()->subHour());

    Artisan::call('dply:site:abort-deploy', [
        'site' => $site->slug,
        '--id' => $older->id,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['deployment_id'])->toBe($older->id);
    expect($older->fresh()->status)->toBe('failed');
    expect($newer->fresh()->status)->toBe('running');
});
test('refuses to abort recent deploy without force', function () {
    $site = makeSite();
    $deployment = seedDeployment($site, SiteDeployment::STATUS_RUNNING, now()->subMinutes(2));

    $exit = Artisan::call('dply:site:abort-deploy', ['site' => $site->slug]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('only', $output);
    expect($deployment->fresh()->status)->toBe('running');
});
test('force overrides age guard', function () {
    $site = makeSite();
    $deployment = seedDeployment($site, SiteDeployment::STATUS_RUNNING, now()->subSeconds(30));

    $exit = Artisan::call('dply:site:abort-deploy', [
        'site' => $site->slug,
        '--force' => true,
    ]);

    expect($exit)->toBe(0);
    expect($deployment->fresh()->status)->toBe('failed');
});
test('refuses to abort already succeeded deployment', function () {
    $site = makeSite();
    $deployment = seedDeployment($site, SiteDeployment::STATUS_SUCCESS, now()->subHour());

    $exit = Artisan::call('dply:site:abort-deploy', [
        'site' => $site->slug,
        '--id' => $deployment->id,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not "running"', $output);
});
test('no running deployments returns failure', function () {
    $site = makeSite();
    seedDeployment($site, SiteDeployment::STATUS_SUCCESS, now()->subHour());

    $exit = Artisan::call('dply:site:abort-deploy', ['site' => $site->slug]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('No running deployments', $output);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:abort-deploy', ['site' => 'nope']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
function makeSite(): Site
{
    $server = Server::factory()->create();

    return Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
    ]);
}
function seedDeployment(Site $site, string $status, \DateTimeInterface $startedAt): SiteDeployment
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
