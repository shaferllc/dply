<?php

declare(strict_types=1);

namespace Tests\Feature\CreateCloudSiteTest;

use App\Modules\Cloud\Actions\CreateCloudSite;
use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\ProvisionCloudSiteJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('creates cloud server and site and dispatches provision', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    $site = (new CreateCloudSite)->handle($user, $org, [
        'name' => 'Acme API',
        'image' => 'ghcr.io/acme/api:v1',
        'port' => 8080,
        'region' => 'nyc',
        'backend' => 'auto',
        'env_file_content' => "APP_ENV=production\nLOG_LEVEL=info",
    ]);

    expect($site->type)->toBe(SiteType::Container);
    expect($site->container_backend)->toBe('digitalocean_app_platform');
    expect($site->container_image)->toBe('ghcr.io/acme/api:v1');
    expect($site->container_port)->toBe(8080);
    expect($site->container_region)->toBe('nyc');
    expect($site->server)->not->toBeNull();
    expect($site->server->hostKind())->toBe(Server::HOST_KIND_DPLY_CLOUD);

    Queue::assertPushed(ProvisionCloudSiteJob::class, fn ($j) => $j->siteId === $site->id);
});
test('explicit backend overrides auto pick', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws_app_runner',
        'name' => 'AWS',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1'],
    ]);

    $site = (new CreateCloudSite)->handle($user, $org, [
        'name' => 'Pinned to AWS',
        'image' => 'public.ecr.aws/acme/api:v1',
        'port' => 8080,
        'region' => 'us-east-1',
        'backend' => 'aws_app_runner',
    ]);

    expect($site->container_backend)->toBe('aws_app_runner');
});
test('throws when no container credential connected', function () {
    config(['server_provision_fake.env_flag' => false]);
    [$user, $org] = scaffold();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No container backend connected');
    (new CreateCloudSite)->handle($user, $org, [
        'name' => 'Lonely',
        'image' => 'nginx:1',
        'port' => 80,
        'region' => 'nyc',
        'backend' => 'auto',
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
