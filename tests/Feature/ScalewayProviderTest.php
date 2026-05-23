<?php

namespace Tests\Feature\ScalewayProviderTest;

use App\Actions\Servers\DeleteServerAction;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Jobs\PollScalewayIpJob;
use App\Jobs\ProvisionScalewayServerJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Create\StepType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Component;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['server_providers.enabled.scaleway' => true]);
    Feature::define('provider.scaleway', fn (): bool => true);
    Feature::flushCache();
});

function scalewayTestUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('list server provider cards includes scaleway when enabled', function () {
    $user = scalewayTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->toContain('scaleway');
});

test('credentials nav includes scaleway when enabled', function () {
    config(['server_providers.enabled.scaleway' => true]);

    $nav = CredentialsIndex::credentialProviderNav();
    $allIds = [];
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $allIds[] = $item['id'];
        }
    }

    expect($allIds)->toContain('scaleway');
});

test('server create wizard step shows provider mode copy', function () {
    $user = scalewayTestUser();

    Livewire::actingAs($user)
        ->test(StepType::class)
        ->assertSee('Provision with a provider', false)
        ->assertSee('DigitalOcean', false);
});

test('scaleway credential store validates token via api', function () {
    Http::fake([
        'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers*' => Http::response(['servers' => []], 200),
    ]);

    $user = scalewayTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('scaleway_api_token', 'scw_test_token')
        ->set('scaleway_project_id', '11111111-1111-1111-1111-111111111111')
        ->set('scaleway_name', 'Production Scaleway')
        ->call('storeScaleway')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'scaleway',
        'name' => 'Production Scaleway',
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/zones/fr-par-1/servers')
        && $request->hasHeader('X-Auth-Token', 'scw_test_token'));
});

test('scaleway credential store rejects invalid token', function () {
    Http::fake([
        'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers*' => Http::response([
            'message' => 'invalid token',
        ], 401),
    ]);

    $user = scalewayTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('scaleway_api_token', 'bad-token')
        ->set('scaleway_project_id', '11111111-1111-1111-1111-111111111111')
        ->call('storeScaleway')
        ->assertHasErrors('scaleway_api_token');

    $this->assertDatabaseCount('provider_credentials', 0);
});

test('resolve scaleway catalog returns zones and server types', function () {
    Http::fake([
        'https://api.scaleway.com/instance/v1/zones/fr-par-1/products/servers' => Http::response([
            'servers' => [
                ['name' => 'DEV1-S', 'ncpus' => 2],
            ],
        ], 200),
    ]);

    $user = scalewayTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'scaleway',
        'credentials' => [
            'api_token' => 'scw_test',
            'project_id' => '11111111-1111-1111-1111-111111111111',
        ],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'scaleway', (string) $credential->id, 'fr-par-1');

    expect($catalog['regions'])->not->toBeEmpty();
    expect(collect($catalog['regions'])->pluck('value'))->toContain('fr-par-1');
    expect($catalog['sizes'])->toHaveCount(1);
    expect($catalog['sizes'][0]['value'])->toBe('DEV1-S');
    expect($catalog['region_label'])->toBe('Zone');
});

test('store server from create form dispatches scaleway provision job', function () {
    Queue::fake();

    $user = scalewayTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'scaleway',
        'credentials' => [
            'api_token' => 'scw_test',
            'project_id' => '11111111-1111-1111-1111-111111111111',
        ],
    ]);

    $form = new ServerCreateForm(scalewayFormComponent(), 'form');
    $form->type = 'scaleway';
    $form->name = 'app-server';
    $form->provider_credential_id = (string) $credential->id;
    $form->region = 'fr-par-1';
    $form->size = 'DEV1-S';
    $form->install_profile = 'laravel_app';
    $form->server_role = 'application';
    $form->webserver = 'nginx';
    $form->php_version = '8.3';
    $form->database = 'mysql84';
    $form->cache_service = 'redis';

    $server = StoreServerFromCreateForm::run($user, $org, $form);

    expect($server->provider)->toBe(ServerProvider::Scaleway);
    expect($server->status)->toBe(Server::STATUS_PENDING);

    Queue::assertPushed(ProvisionScalewayServerJob::class, fn (ProvisionScalewayServerJob $job) => $job->server->is($server));
});

test('provision scaleway job creates server with authorized key tag', function () {
    Queue::fake();

    Http::fake([
        'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers' => Http::response([
            'server' => ['id' => 'scw-server-9001'],
        ], 201),
    ]);

    $user = scalewayTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'scaleway',
        'credentials' => [
            'api_token' => 'scw_test',
            'project_id' => '11111111-1111-1111-1111-111111111111',
        ],
    ]);

    $server = Server::factory()->scaleway()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionScalewayServerJob($server))->handle();

    $server->refresh();

    expect($server->provider_id)->toBe('scw-server-9001');
    expect($server->status)->toBe(Server::STATUS_PROVISIONING);
    expect($server->ssh_private_key)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers'
        && isset($request->data()['tags'][0])
        && str_starts_with($request->data()['tags'][0], 'AUTHORIZED_KEY='));

    Queue::assertPushed(PollScalewayIpJob::class);
});

test('provision scaleway job surfaces api errors on server meta', function () {
    Http::fake([
        'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers' => Http::response([
            'message' => 'unauthorized',
        ], 401),
    ]);

    $user = scalewayTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'scaleway',
        'credentials' => [
            'api_token' => 'scw_test',
            'project_id' => '11111111-1111-1111-1111-111111111111',
        ],
    ]);

    $server = Server::factory()->scaleway()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionScalewayServerJob($server))->handle();

    $server->refresh();

    expect($server->status)->toBe(Server::STATUS_ERROR);
    expect($server->meta['provision_error']['provider'] ?? null)->toBe('scaleway');
    expect($server->meta['provision_error']['message'] ?? '')->toContain('unauthorized');
});

test('poll scaleway ip job sets ready when public ip available', function () {
    Queue::fake();

    Http::fake([
        'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers/scw-server-9001' => Http::response([
            'server' => [
                'id' => 'scw-server-9001',
                'public_ip' => '203.0.113.10',
            ],
        ], 200),
    ]);

    $user = scalewayTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'scaleway',
        'credentials' => [
            'api_token' => 'scw_test',
            'project_id' => '11111111-1111-1111-1111-111111111111',
        ],
    ]);

    $server = Server::factory()->scaleway()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'scw-server-9001',
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new PollScalewayIpJob($server))->handle();

    $server->refresh();

    expect($server->ip_address)->toBe('203.0.113.10');
    expect($server->status)->toBe(Server::STATUS_READY);

    Queue::assertPushed(WaitForServerSshReadyJob::class);
});

test('delete server action destroys scaleway instance', function () {
    Http::fake([
        'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers/scw-server-9001' => Http::response([], 200),
    ]);

    $user = scalewayTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'scaleway',
        'credentials' => [
            'api_token' => 'scw_test',
            'project_id' => '11111111-1111-1111-1111-111111111111',
        ],
    ]);

    $server = Server::factory()->scaleway()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'scw-server-9001',
    ]);

    app(DeleteServerAction::class)->execute($server, $user);

    $this->assertModelMissing($server);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.scaleway.com/instance/v1/zones/fr-par-1/servers/scw-server-9001');
});

test('scaleway disabled via config hides provider from create cards', function () {
    config(['server_providers.enabled.scaleway' => false]);

    $user = scalewayTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('scaleway');
});

test('fake cloud provision intercepts scaleway servers in testing', function () {
    config([
        'server_provision_fake.env_flag' => true,
        'server_provision_fake.allowed_environments' => ['testing'],
    ]);

    $user = scalewayTestUser();
    $org = $user->currentOrganization();

    $server = Server::factory()->scaleway()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    expect(FakeCloudProvision::shouldInterceptVmProvision($server))->toBeTrue();
});

function scalewayFormComponent(): Component
{
    return new class extends Component
    {
        public function render(): string
        {
            return '';
        }
    };
}
