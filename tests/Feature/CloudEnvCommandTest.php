<?php

declare(strict_types=1);

namespace Tests\Feature\CloudEnvCommandTest;

use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\RedeployCloudSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Use the fake backend so backend->updateEnvVars no-ops without HTTP.
    config(['server_provision_fake.env_flag' => true]);
});
test('set merges keys into existing env', function () {
    Queue::fake();
    $site = makeContainerSite("APP_ENV=staging\nLOG_LEVEL=info\n");

    $exit = Artisan::call('dply:cloud:env', [
        'site' => $site->name,
        '--set' => ['LOG_LEVEL=debug', 'NEW_KEY=value'],
    ]);

    expect($exit)->toBe(0);
    $fresh = $site->fresh();
    $this->assertStringContainsString('APP_ENV=staging', $fresh->env_file_content);
    $this->assertStringContainsString('LOG_LEVEL=debug', $fresh->env_file_content);
    $this->assertStringContainsString('NEW_KEY=value', $fresh->env_file_content);
    $this->assertStringNotContainsString('LOG_LEVEL=info', $fresh->env_file_content);
    Queue::assertPushed(RedeployCloudSiteJob::class);
});
test('file replaces env content', function () {
    Queue::fake();
    $site = makeContainerSite("OLD=1\n");
    $tmp = tempnam(sys_get_temp_dir(), 'dply-env-');
    file_put_contents($tmp, "FRESH=2\nOTHER=3\n");

    try {
        $exit = Artisan::call('dply:cloud:env', [
            'site' => $site->name,
            '--file' => $tmp,
        ]);
    } finally {
        @unlink($tmp);
    }

    expect($exit)->toBe(0);
    $fresh = $site->fresh();
    $this->assertStringContainsString('FRESH=2', $fresh->env_file_content);
    $this->assertStringNotContainsString('OLD=1', $fresh->env_file_content);
});
test('no redeploy flag skips dispatch', function () {
    Queue::fake();
    $site = makeContainerSite('');

    $exit = Artisan::call('dply:cloud:env', [
        'site' => $site->name,
        '--set' => ['KEY=value'],
        '--no-redeploy' => true,
    ]);

    expect($exit)->toBe(0);
    Queue::assertNotPushed(RedeployCloudSiteJob::class);
});
test('rejects when neither file nor set provided', function () {
    $site = makeContainerSite('');

    $exit = Artisan::call('dply:cloud:env', ['site' => $site->name]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Pass --file or one or more --set', Artisan::output());
});
test('rejects when both file and set provided', function () {
    $site = makeContainerSite('');

    $exit = Artisan::call('dply:cloud:env', [
        'site' => $site->name,
        '--file' => '/tmp/nonexistent',
        '--set' => ['KEY=v'],
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not both', Artisan::output());
});
test('rejects unreadable file', function () {
    $site = makeContainerSite('');

    $exit = Artisan::call('dply:cloud:env', [
        'site' => $site->name,
        '--file' => '/path/that/definitely/does/not/exist',
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('File not readable', Artisan::output());
});
test('rejects non cloud site', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);
    $vmSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'PHP Site',
        'type' => SiteType::Php,
    ]);

    $exit = Artisan::call('dply:cloud:env', [
        'site' => $vmSite->name,
        '--set' => ['KEY=v'],
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not a cloud container site', Artisan::output());
});
test('missing site', function () {
    $exit = Artisan::call('dply:cloud:env', [
        'site' => 'does-not-exist',
        '--set' => ['KEY=v'],
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', Artisan::output());
});
function makeContainerSite(string $envContent): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'edge-app',
        'slug' => 'edge-app',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'container_backend_id' => 'fake-app-1',
        'env_file_content' => $envContent,
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);
}
