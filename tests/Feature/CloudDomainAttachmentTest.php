<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDomainAttachmentTest;
use App\Enums\SiteType;
use App\Jobs\AttachCloudDomainJob;
use App\Jobs\DetachCloudDomainJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('attach dispatches job with normalized hostname', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_domain_input', 'HTTPS://Api.Example.com/')
        ->call('attachContainerDomain');

    Queue::assertPushed(AttachCloudDomainJob::class, function (AttachCloudDomainJob $job) use ($site): bool {
        return $job->siteId === $site->id && $job->hostname === 'api.example.com';
    });
});
test('attach rejects invalid hostname with toast', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_domain_input', 'no-tld')
        ->call('attachContainerDomain')
        ->assertDispatched('notify');

    Queue::assertNotPushed(AttachCloudDomainJob::class);
});
test('detach dispatches job', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('detachContainerDomain', 'old.example.com');

    Queue::assertPushed(DetachCloudDomainJob::class, function (DetachCloudDomainJob $job) use ($site): bool {
        return $job->siteId === $site->id && $job->hostname === 'old.example.com';
    });
});
test('attach job calls do backend and persists meta', function () {
    Http::fake([
        // GET app first
        'api.digitalocean.com/v2/apps/app-12345' => Http::response([
            'app' => ['id' => 'app-12345', 'spec' => ['name' => 'test', 'services' => [['name' => 'web']]]],
        ], 200),
        // Then PUT spec back
        'api.digitalocean.com/v2/apps/app-12345*' => Http::response([], 200),
    ]);

    [$user, $server, $site, $credential] = makeContainerSite();
    $site->update(['container_backend_id' => 'app-12345']);

    (new AttachCloudDomainJob($site->id, 'api.example.com'))->handle();

    $fresh = $site->fresh();
    expect($fresh->meta['container']['domains'])->toHaveKey('api.example.com');
});
test('dashboard renders attached domains', function () {
    [$user, $server, $site] = makeContainerSite();
    $site->update(['meta' => ['container' => ['domains' => [
        'api.example.com' => [
            'attached_at' => now()->toIso8601String(),
            'validation_records' => [],
        ],
    ]]]]);

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Custom domains')
        ->assertSee('api.example.com');
});
test('dashboard shows validation records when present', function () {
    [$user, $server, $site] = makeContainerSite();
    $site->update(['meta' => ['container' => ['domains' => [
        'api.example.com' => [
            'attached_at' => now()->toIso8601String(),
            'validation_records' => [
                ['type' => 'CNAME', 'name' => '_acm-challenge.api.example.com', 'value' => 'abc.acm-validations.aws', 'status' => 'PENDING_VALIDATION'],
            ],
        ],
    ]]]]);

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertSee('DNS validation records')
        ->assertSee('_acm-challenge.api.example.com')
        ->assertSee('abc.acm-validations.aws');
});
/**
 * @return array{0: User, 1: Server, 2: Site, 3: ProviderCredential}
 */
function makeContainerSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $credential = ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'ghcr.io/acme/api:v1',
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);

    return [$user, $server, $site, $credential];
}
