<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\CreateDedicatedRedisVmTest;

use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Sites\ResourceMap;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use App\Modules\Database\Jobs\ProvisionDedicatedRedisVmJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('provisioning a dedicated redis vm uses the redis_server recipe', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
    ]);

    $appServer = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
        'region' => 'sfo2',
        'ip_address' => '203.0.113.10',
        'private_ip_address' => '10.10.0.10',
        'ssh_private_key' => 'fake-key',
    ]);
    $site = Site::factory()->create([
        'server_id' => $appServer->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'acme',
    ]);

    $cacheServer = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'region' => 'sfo2',
        'ip_address' => '',
        'meta' => [
            'server_role' => 'redis',
            'install_profile' => 'redis_server',
            'cache_service' => 'redis',
        ],
    ]);

    app()->instance(StoreServerFromCreateForm::class, new class($cacheServer)
    {
        public function __construct(private Server $cacheServer) {}

        public function handle($user, $org, ServerCreateForm $form): Server
        {
            expect($form->server_role)->toBe('redis')
                ->and($form->install_profile)->toBe('redis_server')
                ->and($form->cache_service)->toBe('redis')
                ->and($form->webserver)->toBe('none')
                ->and($form->php_version)->toBe('none')
                ->and($form->database)->toBe('none')
                ->and($form->cache_remote_access)->toBeTrue()
                ->and($form->cache_require_password)->toBeTrue()
                ->and($form->cache_allowed_from)->toBe('10.10.0.10')
                ->and($form->size)->toBe('s-2vcpu-4gb')
                ->and($form->region)->toBe('sfo2');

            return $this->cacheServer;
        }
    });

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->set('bindingModalType', 'redis')
        ->set('bindingModalMode', 'provision')
        ->set('bindingForm', [
            'engine' => 'redis',
            'name' => 'acme_redis',
            'placement' => 'cache_vm',
            'vm_size' => 's-2vcpu-4gb',
            'use_for_drivers' => true,
        ])
        ->call('saveBinding')
        ->assertHasNoErrors();

    $service = ServerCacheService::query()->where('server_id', $cacheServer->id)->first();
    expect($service)->not->toBeNull()
        ->and($service->engine)->toBe('redis')
        ->and($service->status)->toBe(ServerCacheService::STATUS_INSTALLING)
        ->and($service->auth_password)->not->toBe('');

    $binding = SiteBinding::query()->where('site_id', $site->id)->where('type', 'redis')->first();
    expect($binding)->not->toBeNull()
        ->and($binding->status)->toBe(SiteBinding::STATUS_PROVISIONING)
        ->and($binding->target_type)->toBe('server_cache_service')
        ->and($binding->target_id)->toBe((string) $service->id)
        ->and($binding->config['placement'])->toBe('cache_vm');

    Queue::assertPushed(ProvisionDedicatedRedisVmJob::class, function (ProvisionDedicatedRedisVmJob $job) use ($cacheServer, $site, $service, $binding): bool {
        return $job->serverId === (string) $cacheServer->id
            && $job->siteId === (string) $site->id
            && $job->serverCacheServiceId === (string) $service->id
            && $job->siteBindingId === (string) $binding->id;
    });
});

test('a stale sfo region is remapped to sfo3 before create', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
    ]);

    $appServer = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
        'region' => 'sfo',
        'ip_address' => '203.0.113.10',
        'private_ip_address' => '10.10.0.10',
        'ssh_private_key' => 'fake-key',
    ]);
    $site = Site::factory()->create([
        'server_id' => $appServer->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'acme',
    ]);

    $cacheServer = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'region' => 'sfo3',
        'ip_address' => '',
    ]);

    app()->instance(StoreServerFromCreateForm::class, new class($cacheServer)
    {
        public function __construct(private Server $cacheServer) {}

        public function handle($user, $org, ServerCreateForm $form): Server
        {
            expect($form->region)->toBe('sfo3');

            return $this->cacheServer;
        }
    });

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->set('bindingModalType', 'redis')
        ->set('bindingModalMode', 'provision')
        ->set('bindingForm', [
            'engine' => 'redis',
            'name' => 'acme_redis',
            'placement' => 'cache_vm',
            'vm_size' => 's-2vcpu-4gb',
        ])
        ->call('saveBinding')
        ->assertHasNoErrors();
});
