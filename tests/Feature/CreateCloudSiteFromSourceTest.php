<?php

declare(strict_types=1);

namespace Tests\Feature\CreateCloudSiteFromSourceTest;

use App\Actions\Cloud\CreateCloudSiteFromSource;
use App\Enums\SiteType;
use App\Jobs\ProvisionCloudSiteJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('creates site with source meta and dispatches provision', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    $site = (new CreateCloudSiteFromSource)->handle($user, $org, [
        'name' => 'API service',
        'repo' => 'acme/api',
        'branch' => 'main',
        'dockerfile_path' => 'Dockerfile',
        'deploy_on_push' => true,
        'port' => 8080,
        'region' => 'nyc',
        'backend' => 'digitalocean_app_platform',
    ]);

    expect($site->type)->toBe(SiteType::Container);
    expect($site->container_image)->toBeNull();
    expect($site->container_backend)->toBe('digitalocean_app_platform');

    $source = $site->meta['container']['source'] ?? [];
    expect($source['repo'])->toBe('acme/api');
    expect($source['branch'])->toBe('main');
    expect($source['dockerfile_path'])->toBe('Dockerfile');
    expect($source['deploy_on_push'])->toBeTrue();

    $server = Server::query()->find($site->server_id);
    expect($server)->not->toBeNull();
    expect($server->meta['host_kind'] ?? null)->toBe(Server::HOST_KIND_DPLY_CLOUD);

    Queue::assertPushed(ProvisionCloudSiteJob::class);
});
test('normalizes full github url to owner name', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    $site = (new CreateCloudSiteFromSource)->handle($user, $org, [
        'name' => 'svc',
        'repo' => 'https://github.com/acme/api.git',
        'branch' => 'main',
        'backend' => 'digitalocean_app_platform',
        'region' => 'nyc',
    ]);

    expect($site->meta['container']['source']['repo'])->toBe('acme/api');
});
test('omits dockerfile path from meta when blank', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    $site = (new CreateCloudSiteFromSource)->handle($user, $org, [
        'name' => 'svc',
        'repo' => 'acme/api',
        'branch' => 'main',
        'backend' => 'digitalocean_app_platform',
        'region' => 'nyc',
    ]);

    $this->assertArrayNotHasKey('dockerfile_path', $site->meta['container']['source']);
});
test('rejects blank repo', function () {
    [$user, $org] = scaffold();

    $this->expectException(\InvalidArgumentException::class);
    (new CreateCloudSiteFromSource)->handle($user, $org, [
        'name' => 'svc',
        'repo' => '',
        'branch' => 'main',
        'backend' => 'digitalocean_app_platform',
        'region' => 'nyc',
    ]);
});
test('auto backend picks a connected one', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws_app_runner',
        'name' => 'AWS',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's'],
    ]);

    $site = (new CreateCloudSiteFromSource)->handle($user, $org, [
        'name' => 'svc',
        'repo' => 'acme/api',
        'branch' => 'main',
        'backend' => 'auto',
        'region' => 'us-east-1',
    ]);

    expect($site->container_backend)->toBe('aws_app_runner');
});
test('auto backend throws when none connected', function () {
    config(['server_provision_fake.env_flag' => false]);
    [$user, $org] = scaffold();

    $this->expectException(\RuntimeException::class);
    (new CreateCloudSiteFromSource)->handle($user, $org, [
        'name' => 'svc',
        'repo' => 'acme/api',
        'branch' => 'main',
        'backend' => 'auto',
        'region' => 'nyc',
    ]);
});
/**
 * @return array{0: User, 1: Organization}
 */
function scaffold(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return [$user, $org];
}
