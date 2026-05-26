<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDeployCommandTest;

use App\Enums\SiteType;
use App\Jobs\ProvisionCloudSiteJob;
use App\Jobs\RedeployCloudSiteJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('creates edge site for new name', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    $exit = Artisan::call('dply:cloud:deploy', [
        'name' => 'New Service',
        '--image' => 'ghcr.io/acme/api:v1',
        '--region' => 'nyc',
        '--backend' => 'digitalocean_app_platform',
        '--user' => $user->email,
        '--org' => $org->id,
    ]);

    expect($exit)->toBe(0);
    $this->assertDatabaseHas('sites', [
        'name' => 'New Service',
        'container_image' => 'ghcr.io/acme/api:v1',
        'container_backend' => 'digitalocean_app_platform',
    ]);
    Queue::assertPushed(ProvisionCloudSiteJob::class);
});
test('redeploys existing site with image bump', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Existing Service',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'ghcr.io/acme/api:v1',
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
    ]);

    $exit = Artisan::call('dply:cloud:deploy', [
        'name' => 'Existing Service',
        '--image' => 'ghcr.io/acme/api:v2',
        '--user' => $user->email,
        '--org' => $org->id,
    ]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Image-bump redeploy queued', Artisan::output());
    Queue::assertPushed(RedeployCloudSiteJob::class, function (RedeployCloudSiteJob $j): bool {
        return $j->newImage === 'ghcr.io/acme/api:v2';
    });
});
test('fails when image missing', function () {
    $exit = Artisan::call('dply:cloud:deploy', ['name' => 'Foo']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('--image is required', Artisan::output());
});
test('fails when no org resolvable', function () {
    $exit = Artisan::call('dply:cloud:deploy', [
        'name' => 'Foo',
        '--image' => 'nginx:1',
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Could not resolve organization', Artisan::output());
});
test('fails when no credential connected', function () {
    config(['server_provision_fake.env_flag' => false]);
    [$user, $org] = scaffold();

    $exit = Artisan::call('dply:cloud:deploy', [
        'name' => 'No Backend',
        '--image' => 'nginx:1',
        '--region' => 'nyc',
        '--user' => $user->email,
        '--org' => $org->id,
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('No container backend connected', Artisan::output());
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
