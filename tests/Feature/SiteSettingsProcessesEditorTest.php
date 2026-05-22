<?php

declare(strict_types=1);

namespace Tests\Feature\SiteSettingsProcessesEditorTest;
use App\Jobs\ProvisionSiteSystemdUnitsJob;
use App\Jobs\TearDownSiteSystemdUnitJob;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\User;
use App\Services\Sites\SiteSystemdProvisioner;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('add site process creates worker row and dispatches systemd job', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('new_site_process_type', SiteProcess::TYPE_WORKER)
        ->set('new_site_process_name', 'sidekiq')
        ->set('new_site_process_command', 'bundle exec sidekiq')
        ->call('addSiteProcess')
        ->assertSet('new_site_process_name', '')
        ->assertSet('new_site_process_command', '');

    $proc = $site->processes()->where('name', 'sidekiq')->first();
    expect($proc)->not->toBeNull();
    expect($proc->type)->toBe(SiteProcess::TYPE_WORKER);
    expect($proc->command)->toBe('bundle exec sidekiq');
    expect($proc->is_active)->toBeTrue();

    Queue::assertPushed(ProvisionSiteSystemdUnitsJob::class, fn ($j) => $j->siteId === $site->id);
});
test('add site process rejects reserved web name', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('new_site_process_name', 'web')
        ->set('new_site_process_command', 'something else')
        ->call('addSiteProcess')
        ->assertHasErrors(['new_site_process_name']);

    expect($site->processes()->where('name', 'web')->where('type', SiteProcess::TYPE_WORKER)->count())->toBe(0);
});
test('add site process rejects duplicate name', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();
    $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'worker',
        'command' => 'npm run worker',
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('new_site_process_name', 'worker')
        ->set('new_site_process_command', 'something different')
        ->call('addSiteProcess')
        ->assertHasErrors(['new_site_process_name']);

    expect($site->processes()->where('name', 'worker')->count())->toBe(1);
});
test('add site process validates name pattern', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('new_site_process_name', 'bad name with spaces')
        ->set('new_site_process_command', 'echo hi')
        ->call('addSiteProcess')
        ->assertHasErrors(['new_site_process_name']);
});
test('remove site process deletes a worker', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();
    $process = $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'celery',
        'command' => 'celery -A app worker',
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('removeSiteProcess', $process->id);

    expect($site->processes()->where('name', 'celery')->count())->toBe(0);

    Queue::assertPushed(TearDownSiteSystemdUnitJob::class, function ($job) use ($site) {
        return $job->siteId === $site->id
            && str_contains($job->unitName, 'celery.service');
    });
});
test('remove site process skips teardown job for php site', function () {
    Queue::fake();
    [$user, $server, $site] = makePhpSite();
    $process = $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'horizon',
        'command' => 'php artisan horizon',
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('removeSiteProcess', $process->id);

    Queue::assertNotPushed(TearDownSiteSystemdUnitJob::class);
});
test('remove site process refuses to delete web row', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();
    $web = $site->processes()->where('type', SiteProcess::TYPE_WEB)->first();
    expect($web)->not->toBeNull('Site::created hook should have made a web row');

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('removeSiteProcess', $web->id);

    expect($site->processes()->where('id', $web->id)->first())->not->toBeNull();
});
test('toggle active flips inactive to active', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();
    $process = $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'sidekiq',
        'command' => 'bundle exec sidekiq',
        'is_active' => false,
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('toggleSiteProcessActive', $process->id);

    expect((bool) $process->refresh()->is_active)->toBeTrue();
});
test('toggle active refuses to deactivate web row', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();
    $web = $site->processes()->where('type', SiteProcess::TYPE_WEB)->first();
    expect($web)->not->toBeNull();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('toggleSiteProcessActive', $web->id);

    expect((bool) $web->refresh()->is_active)->toBeTrue();
});
test('set scale updates process and dispatches systemd job', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();
    $process = $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'worker',
        'command' => 'npm run worker',
        'scale' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('setSiteProcessScale', $process->id, 3);

    expect((int) $process->refresh()->scale)->toBe(3);
    Queue::assertPushed(ProvisionSiteSystemdUnitsJob::class);
});
test('set scale rejects out of bounds values', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();
    $process = $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'worker',
        'command' => 'npm run worker',
        'scale' => 1,
    ]);

    // Below minimum.
    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('setSiteProcessScale', $process->id, 0);
    expect((int) $process->refresh()->scale)->toBe(1);

    // Above maximum.
    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('setSiteProcessScale', $process->id, 100);
    expect((int) $process->refresh()->scale)->toBe(1);
});
test('restart site process calls provisioner', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();
    $process = $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'sidekiq',
        'command' => 'bundle exec sidekiq',
    ]);

    $provisioner = \Mockery::mock(SiteSystemdProvisioner::class);
    $provisioner->shouldReceive('restartUnit')->once()->andReturn('');
    $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('restartSiteProcess', $process->id)
        ->assertHasNoErrors();
});
test('restart refuses web process', function () {
    Queue::fake();
    [$user, $server, $site] = makeNodeSite();
    $web = $site->processes()->where('type', SiteProcess::TYPE_WEB)->first();

    $provisioner = \Mockery::mock(SiteSystemdProvisioner::class);
    $provisioner->shouldNotReceive('restartUnit');
    $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('restartSiteProcess', $web->id);
});
test('restart refuses php site', function () {
    Queue::fake();
    [$user, $server, $site] = makePhpSite();
    $process = $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'horizon',
        'command' => 'php artisan horizon',
    ]);

    $provisioner = \Mockery::mock(SiteSystemdProvisioner::class);
    $provisioner->shouldNotReceive('restartUnit');
    $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('restartSiteProcess', $process->id);
});
test('does not dispatch systemd job for php site', function () {
    Queue::fake();
    [$user, $server, $site] = makePhpSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('new_site_process_type', SiteProcess::TYPE_WORKER)
        ->set('new_site_process_name', 'horizon')
        ->set('new_site_process_command', 'php artisan horizon')
        ->call('addSiteProcess');

    Queue::assertNotPushed(ProvisionSiteSystemdUnitsJob::class);
});
/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeNodeSite(): array
{
    $user = seedUser();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'meta' => ['webserver' => 'nginx'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'runtime' => 'node',
        'start_command' => 'npm start',
        'internal_port' => 30001,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $server, $site];
}
/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makePhpSite(): array
{
    $user = seedUser();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'meta' => ['webserver' => 'nginx'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
        'runtime_version' => '8.4',
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $server, $site];
}
function seedUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
