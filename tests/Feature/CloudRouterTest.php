<?php

declare(strict_types=1);

namespace Tests\Feature\CloudRouterTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Cloud\AwsAppRunnerBackend;
use App\Services\Cloud\CloudRouter;
use App\Services\Cloud\DigitalOceanAppPlatformBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('backend for returns correct implementation', function () {
    config(['server_provision_fake.env_flag' => false]);
    [$user, $org, $server] = scaffold();
    $doSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'container_backend' => 'digitalocean_app_platform',
    ]);
    $arSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'container_backend' => 'aws_app_runner',
    ]);

    expect(CloudRouter::backendFor($doSite))->toBeInstanceOf(DigitalOceanAppPlatformBackend::class);
    expect(CloudRouter::backendFor($arSite))->toBeInstanceOf(AwsAppRunnerBackend::class);
});
test('backend for returns null for unknown backend', function () {
    [$user, $org, $server] = scaffold();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'container_backend' => null,
    ]);

    expect(CloudRouter::backendFor($site))->toBeNull();
});
test('credential for prefers meta credential id when set', function () {
    [$user, $org, $server] = scaffold();
    $cred1 = makeCredential($user, $org, 'digitalocean', 'First');
    $cred2 = makeCredential($user, $org, 'digitalocean', 'Second');
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'container_backend' => 'digitalocean_app_platform',
        'meta' => ['container' => ['credential_id' => $cred2->id]],
    ]);

    $resolved = CloudRouter::credentialFor($site);
    expect($resolved?->id)->toBe($cred2->id);
});
test('credential for falls back to first matching provider', function () {
    [$user, $org, $server] = scaffold();
    $cred = makeCredential($user, $org, 'aws_app_runner', 'Only one');
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'container_backend' => 'aws_app_runner',
    ]);

    expect(CloudRouter::credentialFor($site)?->id)->toBe($cred->id);
});
test('pick auto backend prefers do over aws', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    makeCredential($user, $org, 'digitalocean', 'DO');
    makeCredential($user, $org, 'aws_app_runner', 'AWS');

    expect(CloudRouter::pickAutoBackend($org->id))->toBe('digitalocean_app_platform');
});
test('pick auto backend returns aws when only aws connected', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    makeCredential($user, $org, 'aws_app_runner', 'AWS');

    expect(CloudRouter::pickAutoBackend($org->id))->toBe('aws_app_runner');
});
test('pick auto backend returns null when no credential', function () {
    config(['server_provision_fake.env_flag' => false]);
    $org = Organization::factory()->create();
    expect(CloudRouter::pickAutoBackend($org->id))->toBeNull();
});
/**
 * @return array{0: User, 1: Organization, 2: Server}
 */
function scaffold(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return [$user, $org, $server];
}
function makeCredential(User $user, Organization $org, string $provider, string $name): ProviderCredential
{
    return ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => $provider,
        'name' => $name,
        'credentials' => $provider === 'aws_app_runner'
            ? ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1']
            : ['api_token' => 't'],
    ]);
}
