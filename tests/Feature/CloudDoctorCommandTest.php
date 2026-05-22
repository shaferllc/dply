<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDoctorCommandTest;
use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('reports healthy edge site with credential', function () {
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO production',
        'credentials' => ['api_token' => 't'],
    ]);
    $site = makeContainerSite($user, $org, [
        'name' => 'Healthy API',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => [
            'container' => [
                'backend_id' => 'do-app-123',
                'live_url' => 'https://healthy.ondigitalocean.app',
                'last_phase' => 'ACTIVE',
                'last_poll_at' => now()->toIso8601String(),
                'provisioned_at' => now()->subHour()->toIso8601String(),
            ],
        ],
    ]);

    $exit = Artisan::call('dply:cloud:doctor', [
        'site' => $site->name,
        '--json' => true,
    ]);

    expect($exit)->toBe(0);
    $payload = json_decode(Artisan::output(), true);
    expect($payload['site_name'])->toBe('Healthy API');
    expect($payload['status'])->toBe(Site::STATUS_CONTAINER_ACTIVE);
    expect($payload['backend']['key'])->toBe('digitalocean_app_platform');
    expect($payload['backend']['class'])->not->toBeNull();
    expect($payload['backend']['backend_id'])->toBe('do-app-123');
    expect($payload['credential']['name'])->toBe('DO production');
    expect($payload['live']['url'])->toBe('https://healthy.ondigitalocean.app');
    expect($payload['live']['last_phase'])->toBe('ACTIVE');
    expect($payload['probe'])->toBeNull();
    expect($payload['drift'])->toBe([]);
    expect($payload['timeline'])->not->toBeEmpty();
});
test('fails when site missing', function () {
    $exit = Artisan::call('dply:cloud:doctor', ['site' => 'nope']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', Artisan::output());
});
test('fails when site is not edge', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'PHP Site',
        'type' => SiteType::Php,
    ]);

    $exit = Artisan::call('dply:cloud:doctor', ['site' => $site->name]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not a cloud container site', Artisan::output());
});
test('drift when no credential connected', function () {
    config(['server_provision_fake.env_flag' => false]);
    [$user, $org] = scaffold();
    $site = makeContainerSite($user, $org, [
        'name' => 'Orphan',
        'status' => Site::STATUS_CONTAINER_PROVISIONING,
    ]);

    Artisan::call('dply:cloud:doctor', [
        'site' => $site->name,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['credential'])->toBeNull();
    expect($payload['drift'])->not->toBeEmpty();
    expect(collect($payload['drift'])->contains(fn ($d) => str_contains($d, 'No ProviderCredential connected')))->toBeTrue();
});
test('drift when active without live url or backend id', function () {
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
    $site = makeContainerSite($user, $org, [
        'name' => 'Stuck',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => ['container' => []],
    ]);

    Artisan::call('dply:cloud:doctor', [
        'site' => $site->name,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['drift'])->not->toBeEmpty();
    $drift = collect($payload['drift']);
    expect($drift->contains(fn ($d) => str_contains($d, 'no live URL')))->toBeTrue();
    expect($drift->contains(fn ($d) => str_contains($d, 'no backend_id')))->toBeTrue();
});
test('drift surfaces recent backend error', function () {
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
    $site = makeContainerSite($user, $org, [
        'name' => 'Burning',
        'status' => Site::STATUS_CONTAINER_FAILED,
        'meta' => [
            'container' => [
                'last_error' => 'image pull failed: 401',
                'last_error_at' => now()->subMinutes(5)->toIso8601String(),
                'last_poll_error' => 'connection reset',
            ],
        ],
    ]);

    Artisan::call('dply:cloud:doctor', [
        'site' => $site->name,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['live']['last_error'])->toBe('image pull failed: 401');
    $drift = collect($payload['drift']);
    expect($drift->contains(fn ($d) => str_contains($d, 'image pull failed: 401')))->toBeTrue();
    expect($drift->contains(fn ($d) => str_contains($d, 'connection reset')))->toBeTrue();
});
test('doctor reports scale and github webhook for source site', function () {
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
    $site = makeContainerSite($user, $org, [
        'name' => 'Scaled API',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'container_image' => null,
        'meta' => [
            'container' => [
                'backend_id' => 'do-app-1',
                'live_url' => 'https://x.ondigitalocean.app',
                'source' => ['repo' => 'acme/api', 'branch' => 'main', 'deploy_on_push' => true],
                'instance_count' => 4,
                'size_tier' => 'large',
            ],
        ],
    ]);

    Artisan::call('dply:cloud:doctor', ['site' => $site->name, '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['scale']['instances'])->toBe(4);
    expect($payload['scale']['size_tier'])->toBe('large');
    expect($payload['github_webhook_url'])->not->toBeNull();
    $this->assertStringContainsString('hooks/cloud/'.$site->id.'/github', $payload['github_webhook_url']);
});
test('source mode site reports repo and branch', function () {
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
    $site = makeContainerSite($user, $org, [
        'name' => 'Built API',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'container_image' => null,
        'meta' => [
            'container' => [
                'backend_id' => 'do-app-src',
                'live_url' => 'https://built.ondigitalocean.app',
                'source' => [
                    'repo' => 'acme/built',
                    'branch' => 'main',
                    'dockerfile_path' => 'Dockerfile',
                    'deploy_on_push' => true,
                ],
            ],
        ],
    ]);

    Artisan::call('dply:cloud:doctor', [
        'site' => $site->name,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['mode'])->toBe('source');
    expect($payload['source']['repo'])->toBe('acme/built');
    expect($payload['source']['branch'])->toBe('main');
    expect($payload['source']['dockerfile_path'])->toBe('Dockerfile');
    expect($payload['source']['deploy_on_push'])->toBeTrue();
});
test('timeline is newest first and includes domains', function () {
    [$user, $org] = scaffold();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
    $site = makeContainerSite($user, $org, [
        'name' => 'Timely',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => [
            'container' => [
                'backend_id' => 'do-1',
                'live_url' => 'https://x.ondigitalocean.app',
                'provisioned_at' => '2026-05-01T00:00:00Z',
                'last_deploy_started_at' => '2026-05-02T00:00:00Z',
                'domains' => [
                    'api.example.com' => [
                        'attached_at' => '2026-05-03T00:00:00Z',
                        'status' => 'verified',
                    ],
                ],
            ],
        ],
    ]);

    Artisan::call('dply:cloud:doctor', [
        'site' => $site->name,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['domains'])->toHaveCount(1);
    expect($payload['domains'][0]['hostname'])->toBe('api.example.com');

    $kinds = array_column($payload['timeline'], 'kind');
    expect($kinds[0])->toBe('domain_attached');
    expect($kinds)->toContain('deploy');
    expect($kinds)->toContain('provisioned');
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
/**
 * @param  array<string, mixed>  $overrides
 */
function makeContainerSite(User $user, Organization $org, array $overrides): Site
{
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ], $overrides));
}
