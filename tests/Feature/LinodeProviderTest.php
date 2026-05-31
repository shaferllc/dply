<?php

namespace Tests\Feature\LinodeProviderTest;

use App\Actions\Servers\DeleteServerAction;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Jobs\PollLinodeIpJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Create\StepType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerProviderGate;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Component;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['server_providers.enabled.linode' => true]);
    Feature::define('provider.linode', fn (): bool => true);
    Feature::flushCache();
});

function linodeTestUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('list server provider cards includes linode when enabled', function () {
    $user = linodeTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->toContain('linode');
});

test('linode is omitted when pennant flag is off', function () {
    Feature::define('provider.linode', fn (): bool => false);
    Feature::flushCache();

    $user = linodeTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('linode');
    expect(ServerProviderGate::enabled('linode'))->toBeFalse();
});

test('credentials nav includes linode when enabled', function () {
    config(['server_providers.enabled.linode' => true]);

    $nav = CredentialsIndex::credentialProviderNav();
    $allIds = [];
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $allIds[] = $item['id'];
        }
    }

    expect($allIds)->toContain('linode');
});

test('server create wizard step shows linode in provider mode copy', function () {
    $user = linodeTestUser();

    Livewire::actingAs($user)
        ->test(StepType::class)
        ->assertSee('Linode', false);
});

test('linode credential store validates token via api', function () {
    Http::fake([
        'https://api.linode.com/v4/profile' => Http::response(['username' => 'test'], 200),
    ]);

    $user = linodeTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('linode_api_token', 'lin_test_token')
        ->set('linode_name', 'Production Linode')
        ->call('storeLinode')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'linode',
        'name' => 'Production Linode',
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.linode.com/v4/profile'
        && $request->hasHeader('Authorization', 'Bearer lin_test_token'));
});

test('linode credential store rejects invalid token', function () {
    Http::fake([
        'https://api.linode.com/v4/profile' => Http::response([
            'errors' => [['reason' => 'Invalid Token']],
        ], 401),
    ]);

    $user = linodeTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('linode_api_token', 'bad-token')
        ->call('storeLinode')
        ->assertHasErrors('linode_api_token');

    $this->assertDatabaseCount('provider_credentials', 0);
});

test('resolve linode catalog returns regions and types', function () {
    Http::fake([
        'https://api.linode.com/v4/regions' => Http::response([
            'data' => [
                ['id' => 'us-east', 'label' => 'Newark, NJ'],
            ],
        ], 200),
        'https://api.linode.com/v4/linode/types' => Http::response([
            'data' => [
                [
                    'id' => 'g6-nanode-1',
                    'label' => 'Nanode 1GB',
                    'memory' => 1024,
                    'vcpus' => 1,
                    'price' => [
                        'monthly' => 5.0,
                        'hourly' => 0.0075,
                    ],
                ],
            ],
        ], 200),
    ]);

    $user = linodeTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'linode', (string) $credential->id, '');

    expect($catalog['regions'])->toHaveCount(1);
    expect($catalog['regions'][0]['value'])->toBe('us-east');
    expect($catalog['sizes'])->toHaveCount(1);
    expect($catalog['sizes'][0]['value'])->toBe('g6-nanode-1');
    expect($catalog['region_label'])->toBe('Region');
});

test('store server from create form dispatches linode provision job', function () {
    Queue::fake();

    $user = linodeTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    $form = new ServerCreateForm(linodeFormComponent(), 'form');
    $form->type = 'linode';
    $form->name = 'app-server';
    $form->provider_credential_id = (string) $credential->id;
    $form->region = 'us-east';
    $form->size = 'g6-nanode-1';
    $form->install_profile = 'laravel_app';
    $form->server_role = 'application';
    $form->webserver = 'nginx';
    $form->php_version = '8.3';
    $form->database = 'mysql84';
    $form->cache_service = 'redis';

    $server = StoreServerFromCreateForm::run($user, $org, $form);

    expect($server->provider)->toBe(ServerProvider::Linode);
    expect($server->status)->toBe(Server::STATUS_PENDING);

    Queue::assertPushed(ProvisionLinodeServerJob::class, fn (ProvisionLinodeServerJob $job) => $job->server->is($server));
});

test('provision linode job sends authorized key and creates instance', function () {
    Queue::fake();

    Http::fake([
        'https://api.linode.com/v4/linode/instances' => Http::response([
            'id' => 9001,
        ], 200),
    ]);

    $user = linodeTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    $server = Server::factory()->linode()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionLinodeServerJob($server))->handle();

    $server->refresh();

    expect($server->provider_id)->toBe('9001');
    expect($server->status)->toBe(Server::STATUS_PROVISIONING);
    expect($server->ssh_private_key)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.linode.com/v4/linode/instances'
        && isset($request->data()['authorized_keys'][0])
        && str_contains($request->data()['authorized_keys'][0], 'ssh-'));

    Queue::assertPushed(PollLinodeIpJob::class);
});

test('provision linode job surfaces api errors on server meta', function () {
    Http::fake([
        'https://api.linode.com/v4/linode/instances' => Http::response([
            'errors' => [['reason' => 'Invalid Token']],
        ], 401),
    ]);

    $user = linodeTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    $server = Server::factory()->linode()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionLinodeServerJob($server))->handle();

    $server->refresh();

    expect($server->status)->toBe(Server::STATUS_ERROR);
    expect($server->meta['provision_error']['provider'] ?? null)->toBe('linode');
    expect($server->meta['provision_error']['message'] ?? '')->toContain('Invalid Token');
});

test('poll linode ip job sets ready when public ip available', function () {
    Queue::fake();

    Http::fake([
        'https://api.linode.com/v4/linode/instances/9001' => Http::response([
            'id' => 9001,
            'ipv4' => ['203.0.113.10'],
        ], 200),
    ]);

    $user = linodeTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    $server = Server::factory()->linode()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => '9001',
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new PollLinodeIpJob($server))->handle();

    $server->refresh();

    expect($server->ip_address)->toBe('203.0.113.10');
    expect($server->status)->toBe(Server::STATUS_READY);

    Queue::assertPushed(WaitForServerSshReadyJob::class);
});

test('delete server action destroys linode instance', function () {
    Http::fake([
        'https://api.linode.com/v4/linode/instances/9001' => Http::response([], 200),
    ]);

    $user = linodeTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    $server = Server::factory()->linode()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => '9001',
    ]);

    app(DeleteServerAction::class)->execute($server, $user);

    $this->assertModelMissing($server);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.linode.com/v4/linode/instances/9001');
});

test('linode disabled via config hides provider from create cards', function () {
    config(['server_providers.enabled.linode' => false]);

    $user = linodeTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('linode');
});

test('fake cloud provision intercepts linode servers in testing', function () {
    config([
        'server_provision_fake.env_flag' => true,
        'server_provision_fake.allowed_environments' => ['testing'],
    ]);

    $user = linodeTestUser();
    $org = $user->currentOrganization();

    $server = Server::factory()->linode()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    expect(FakeCloudProvision::shouldInterceptVmProvision($server))->toBeTrue();
});

function linodeFormComponent(): Component
{
    return new class extends Component
    {
        public function render(): string
        {
            return '';
        }
    };
}
