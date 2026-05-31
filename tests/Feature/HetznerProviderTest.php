<?php

namespace Tests\Feature\HetznerProviderTest;

use App\Actions\Servers\DeleteServerAction;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreManagedServer;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Jobs\PollHetznerIpJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Create\StepType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\ServerProviderGate;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Livewire\Component;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['server_providers.enabled.hetzner' => true]);
    Feature::define('provider.hetzner', fn (): bool => true);
    Feature::flushCache();
});

function hetznerTestUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('list server provider cards includes hetzner when enabled', function () {
    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->toContain('hetzner');
});

test('list server provider cards includes server and site counts per provider', function () {
    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $hetznerServer = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider' => ServerProvider::Hetzner,
    ]);

    Site::factory()->count(2)->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'server_id' => $hetznerServer->id,
    ]);

    $hetznerCard = collect(ListServerProviderCards::run($org))->firstWhere('id', 'hetzner');

    expect($hetznerCard)->not->toBeNull()
        ->and($hetznerCard['server_count'])->toBe(1)
        ->and($hetznerCard['site_count'])->toBe(2);
});

test('hetzner is omitted when pennant flag is off', function () {
    Feature::define('provider.hetzner', fn (): bool => false);
    Feature::flushCache();

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('hetzner');
    expect(ServerProviderGate::enabled('hetzner'))->toBeFalse();
});

test('credentials nav includes hetzner when enabled', function () {
    config(['server_providers.enabled.hetzner' => true]);

    $nav = CredentialsIndex::credentialProviderNav();
    $allIds = [];
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $allIds[] = $item['id'];
        }
    }

    expect($allIds)->toContain('hetzner');
});

test('server create wizard step shows hetzner in provider mode copy', function () {
    $user = hetznerTestUser();

    Livewire::actingAs($user)
        ->test(StepType::class)
        ->assertSee('Hetzner', false);
});

test('hetzner credential store validates token via api', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/servers*' => Http::response(['servers' => []], 200),
    ]);

    $user = hetznerTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('hetzner_api_token', 'hzn_test_token')
        ->set('hetzner_name', 'Production Hetzner')
        ->call('storeHetzner')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'hetzner',
        'name' => 'Production Hetzner',
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/servers')
        && $request->hasHeader('Authorization', 'Bearer hzn_test_token'));
});

test('hetzner credential store rejects invalid token', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/servers*' => Http::response([
            'error' => ['message' => 'invalid token'],
        ], 401),
    ]);

    $user = hetznerTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('hetzner_api_token', 'bad-token')
        ->call('storeHetzner')
        ->assertHasErrors('hetzner_api_token');

    $this->assertDatabaseCount('provider_credentials', 0);
});

test('resolve hetzner catalog returns locations and server types', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/locations' => Http::response([
            'locations' => [
                ['id' => 1, 'name' => 'fsn1', 'description' => 'Falkenstein'],
            ],
        ], 200),
        'https://api.hetzner.cloud/v1/server_types' => Http::response([
            'server_types' => [
                [
                    'name' => 'cx22',
                    'memory' => 4,
                    'cores' => 2,
                    'disk' => 80,
                    'prices' => [
                        [
                            'price_monthly' => ['gross' => '5.49'],
                            'price_hourly' => ['gross' => '0.0082'],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hzn_test'],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'hetzner', (string) $credential->id, '');

    expect($catalog['regions'])->toHaveCount(1);
    expect($catalog['regions'][0]['value'])->toBe('fsn1');
    expect($catalog['sizes'])->toHaveCount(1);
    expect($catalog['sizes'][0]['value'])->toBe('cx22');
    expect($catalog['sizes'][0]['disk_gb'])->toBe(80);
    expect($catalog['sizes'][0]['label'])->toContain('80GB');
    expect($catalog['region_label'])->toBe('Location');
});

test('store server from create form dispatches hetzner provision job', function () {
    Queue::fake();

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hzn_test'],
    ]);

    $form = new ServerCreateForm(hetznerFormComponent(), 'form');
    $form->type = 'hetzner';
    $form->name = 'app-server';
    $form->provider_credential_id = (string) $credential->id;
    $form->region = 'fsn1';
    $form->size = 'cx22';
    $form->install_profile = 'laravel_app';
    $form->server_role = 'application';
    $form->webserver = 'nginx';
    $form->php_version = '8.3';
    $form->database = 'mysql84';
    $form->cache_service = 'redis';

    $server = StoreServerFromCreateForm::run($user, $org, $form);

    expect($server->provider)->toBe(ServerProvider::Hetzner);
    expect($server->status)->toBe(Server::STATUS_PENDING);

    Queue::assertPushed(ProvisionHetznerServerJob::class, fn (ProvisionHetznerServerJob $job) => $job->server->is($server));
});

test('provision hetzner job registers ssh key and creates server', function () {
    Queue::fake();

    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
            'ssh_key' => ['id' => 42, 'name' => 'dply-test'],
        ], 201),
        'https://api.hetzner.cloud/v1/servers' => Http::response([
            'server' => ['id' => 9001],
        ], 201),
    ]);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hzn_test'],
    ]);

    $server = Server::factory()->hetzner()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionHetznerServerJob($server))->handle();

    $server->refresh();

    expect($server->provider_id)->toBe('9001');
    expect($server->status)->toBe(Server::STATUS_PROVISIONING);
    expect($server->ssh_private_key)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/ssh_keys'
        && isset($request->data()['public_key']));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/servers'
        && ($request->data()['ssh_keys'] ?? []) === [42]);

    Queue::assertPushed(PollHetznerIpJob::class);
});

test('provision hetzner job surfaces api errors on server meta', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
            'error' => ['message' => 'unauthorized'],
        ], 401),
    ]);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hzn_test'],
    ]);

    $server = Server::factory()->hetzner()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionHetznerServerJob($server))->handle();

    $server->refresh();

    expect($server->status)->toBe(Server::STATUS_ERROR);
    expect($server->meta['provision_error']['provider'] ?? null)->toBe('hetzner');
    expect($server->meta['provision_error']['message'] ?? '')->toContain('unauthorized');
});

test('poll hetzner ip job sets ready when public ip available', function () {
    Queue::fake();

    Http::fake([
        'https://api.hetzner.cloud/v1/servers/9001' => Http::response([
            'server' => [
                'id' => 9001,
                'public_net' => [
                    'ipv4' => ['ip' => '203.0.113.10'],
                ],
            ],
        ], 200),
    ]);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hzn_test'],
    ]);

    $server = Server::factory()->hetzner()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => '9001',
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new PollHetznerIpJob($server))->handle();

    $server->refresh();

    expect($server->ip_address)->toBe('203.0.113.10');
    expect($server->status)->toBe(Server::STATUS_READY);

    Queue::assertPushed(WaitForServerSshReadyJob::class);
});

test('delete server action destroys hetzner instance', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/servers/9001' => Http::response([], 200),
    ]);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hzn_test'],
    ]);

    $server = Server::factory()->hetzner()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => '9001',
    ]);

    app(DeleteServerAction::class)->execute($server, $user);

    $this->assertModelMissing($server);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.hetzner.cloud/v1/servers/9001');
});

test('store managed server creates a dply-hosted hetzner server without a credential', function () {
    Queue::fake();
    config(['managed_servers.hetzner.api_token' => 'dply-platform-token']);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $server = StoreManagedServer::run($user, $org, [
        'name' => 'managed-web',
        'region' => 'fsn1',
        'size' => 'cx22',
        'install_profile' => 'laravel_app',
    ]);

    expect($server->provider)->toBe(ServerProvider::Hetzner);
    expect($server->hosting_backend)->toBe(Server::HOSTING_BACKEND_DPLY);
    expect($server->usesManagedHosting())->toBeTrue();
    expect($server->provider_credential_id)->toBeNull();
    expect($server->status)->toBe(Server::STATUS_PENDING);

    Queue::assertPushed(ProvisionHetznerServerJob::class, fn (ProvisionHetznerServerJob $job) => $job->server->is($server));
});

test('store managed server is rejected when the platform token is not configured', function () {
    config(['managed_servers.hetzner.api_token' => null]);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    expect(fn () => StoreManagedServer::run($user, $org, [
        'name' => 'managed-web',
        'region' => 'fsn1',
        'size' => 'cx22',
        'install_profile' => 'laravel_app',
    ]))->toThrow(ValidationException::class);
});

test('managed hetzner server provisions using the platform token, not a credential', function () {
    Queue::fake();
    config([
        'server_provision_fake.env_flag' => false,
        'managed_servers.hetzner.api_token' => 'dply-platform-token',
    ]);

    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response(['ssh_key' => ['id' => 77]], 201),
        'https://api.hetzner.cloud/v1/servers' => Http::response(['server' => ['id' => 8800]], 201),
    ]);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $server = Server::factory()->hetzner()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => null,
        'hosting_backend' => Server::HOSTING_BACKEND_DPLY,
        'status' => Server::STATUS_PENDING,
        'region' => 'fsn1',
        'size' => 'cx22',
        'meta' => ['server_role' => 'application'],
    ]);

    (new ProvisionHetznerServerJob($server))->handle();

    $server->refresh();

    expect($server->provider_id)->toBe('8800');
    expect($server->status)->toBe(Server::STATUS_PROVISIONING);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/servers'
        && $request->hasHeader('Authorization', 'Bearer dply-platform-token'));
});

test('managed hetzner server errors when the platform token is missing', function () {
    config([
        'server_provision_fake.env_flag' => false,
        'managed_servers.hetzner.api_token' => null,
    ]);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $server = Server::factory()->hetzner()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => null,
        'hosting_backend' => Server::HOSTING_BACKEND_DPLY,
        'status' => Server::STATUS_PENDING,
    ]);

    (new ProvisionHetznerServerJob($server))->handle();

    expect($server->refresh()->status)->toBe(Server::STATUS_ERROR);
});

test('delete managed server destroys the dply-owned instance via the platform token', function () {
    config(['managed_servers.hetzner.api_token' => 'dply-platform-token']);

    Http::fake([
        'https://api.hetzner.cloud/v1/servers/8800' => Http::response([], 200),
    ]);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $server = Server::factory()->hetzner()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => null,
        'hosting_backend' => Server::HOSTING_BACKEND_DPLY,
        'provider_id' => '8800',
    ]);

    app(DeleteServerAction::class)->execute($server, $user);

    $this->assertModelMissing($server);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.hetzner.cloud/v1/servers/8800'
        && $request->hasHeader('Authorization', 'Bearer dply-platform-token'));
});

test('hetzner disabled via config hides provider from create cards', function () {
    config(['server_providers.enabled.hetzner' => false]);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('hetzner');
});

test('fake cloud provision intercepts hetzner servers in testing', function () {
    config([
        'server_provision_fake.env_flag' => true,
        'server_provision_fake.allowed_environments' => ['testing'],
    ]);

    $user = hetznerTestUser();
    $org = $user->currentOrganization();

    $server = Server::factory()->hetzner()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    expect(FakeCloudProvision::shouldInterceptVmProvision($server))->toBeTrue();
});

function hetznerFormComponent(): Component
{
    return new class extends Component
    {
        public function render(): string
        {
            return '';
        }
    };
}
