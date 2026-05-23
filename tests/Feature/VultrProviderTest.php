<?php

namespace Tests\Feature\VultrProviderTest;

use App\Actions\Servers\DeleteServerAction;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Jobs\PollVultrIpJob;
use App\Jobs\ProvisionVultrServerJob;
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
    config(['server_providers.enabled.vultr' => true]);
    Feature::define('provider.vultr', fn (): bool => true);
    Feature::flushCache();
});

function vultrTestUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('list server provider cards includes vultr when enabled', function () {
    $user = vultrTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->toContain('vultr');
});

test('credentials nav includes vultr when enabled', function () {
    $nav = CredentialsIndex::credentialProviderNav();
    $allIds = [];
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $allIds[] = $item['id'];
        }
    }

    expect($allIds)->toContain('vultr');
});

test('server create wizard step shows provider mode copy', function () {
    $user = vultrTestUser();

    Livewire::actingAs($user)
        ->test(StepType::class)
        ->assertSee('Provision with a provider', false)
        ->assertSee('DigitalOcean', false);
});

test('vultr credential store validates token via api', function () {
    Http::fake([
        'https://api.vultr.com/v2/account' => Http::response(['account' => ['email' => 'ops@example.com']], 200),
    ]);

    $user = vultrTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('vultr_api_token', 'vultr_test_token')
        ->set('vultr_name', 'Production Vultr')
        ->call('storeVultr')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'vultr',
        'name' => 'Production Vultr',
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.vultr.com/v2/account'
        && $request->hasHeader('Authorization', 'Bearer vultr_test_token'));
});

test('vultr credential store rejects invalid token', function () {
    Http::fake([
        'https://api.vultr.com/v2/account' => Http::response(['error' => 'Invalid API token'], 401),
    ]);

    $user = vultrTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('vultr_api_token', 'bad-token')
        ->call('storeVultr')
        ->assertHasErrors('vultr_api_token');

    $this->assertDatabaseCount('provider_credentials', 0);
});

test('resolve vultr catalog returns regions and plans', function () {
    Http::fake([
        'https://api.vultr.com/v2/regions' => Http::response([
            'regions' => [
                ['id' => 'ewr', 'city' => 'New Jersey'],
            ],
        ], 200),
        'https://api.vultr.com/v2/plans' => Http::response([
            'plans' => [
                [
                    'id' => 'vc2-1c-1gb',
                    'ram' => 1024,
                    'vcpu_count' => 1,
                    'monthly_cost' => 6.0,
                    'locations' => ['ewr', 'lax'],
                ],
            ],
        ], 200),
    ]);

    $user = vultrTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'vultr', (string) $credential->id, 'ewr');

    expect($catalog['regions'])->toHaveCount(1);
    expect($catalog['regions'][0]['value'])->toBe('ewr');
    expect($catalog['sizes'])->toHaveCount(1);
    expect($catalog['sizes'][0]['value'])->toBe('vc2-1c-1gb');
    expect($catalog['region_label'])->toBe('Region');
});

test('resolve vultr catalog filters plans by selected region', function () {
    Http::fake([
        'https://api.vultr.com/v2/regions' => Http::response([
            'regions' => [
                ['id' => 'ewr', 'city' => 'New Jersey'],
                ['id' => 'lax', 'city' => 'Los Angeles'],
            ],
        ], 200),
        'https://api.vultr.com/v2/plans' => Http::response([
            'plans' => [
                [
                    'id' => 'vc2-1c-1gb',
                    'ram' => 1024,
                    'vcpu_count' => 1,
                    'locations' => ['ewr'],
                ],
                [
                    'id' => 'vc2-2c-4gb',
                    'ram' => 4096,
                    'vcpu_count' => 2,
                    'locations' => ['lax'],
                ],
            ],
        ], 200),
    ]);

    $user = vultrTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'vultr', (string) $credential->id, 'ewr');

    expect($catalog['sizes'])->toHaveCount(1);
    expect($catalog['sizes'][0]['value'])->toBe('vc2-1c-1gb');
});

test('store server from create form dispatches vultr provision job', function () {
    Queue::fake();

    $user = vultrTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $form = new ServerCreateForm(vultrFormComponent(), 'form');
    $form->type = 'vultr';
    $form->name = 'app-server';
    $form->provider_credential_id = (string) $credential->id;
    $form->region = 'ewr';
    $form->size = 'vc2-1c-1gb';
    $form->install_profile = 'laravel_app';
    $form->server_role = 'application';
    $form->webserver = 'nginx';
    $form->php_version = '8.3';
    $form->database = 'mysql84';
    $form->cache_service = 'redis';

    $server = StoreServerFromCreateForm::run($user, $org, $form);

    expect($server->provider)->toBe(ServerProvider::Vultr);
    expect($server->status)->toBe(Server::STATUS_PENDING);

    Queue::assertPushed(ProvisionVultrServerJob::class, fn (ProvisionVultrServerJob $job) => $job->server->is($server));
});

test('provision vultr job registers ssh key and creates instance', function () {
    Queue::fake();

    Http::fake([
        'https://api.vultr.com/v2/ssh-keys' => Http::response([
            'ssh_key' => ['id' => 'ssh-42', 'name' => 'dply-test'],
        ], 201),
        'https://api.vultr.com/v2/instances' => Http::response([
            'instance' => ['id' => 'vps-9001'],
        ], 201),
    ]);

    $user = vultrTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $server = Server::factory()->vultr()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionVultrServerJob($server))->handle();

    $server->refresh();

    expect($server->provider_id)->toBe('vps-9001');
    expect($server->status)->toBe(Server::STATUS_PROVISIONING);
    expect($server->ssh_private_key)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.vultr.com/v2/ssh-keys'
        && isset($request->data()['ssh_key']));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.vultr.com/v2/instances'
        && ($request->data()['sshkey_id'] ?? []) === ['ssh-42']);

    Queue::assertPushed(PollVultrIpJob::class);
});

test('provision vultr job surfaces api errors on server meta', function () {
    Http::fake([
        'https://api.vultr.com/v2/ssh-keys' => Http::response([
            'error' => 'Invalid API token',
        ], 401),
    ]);

    $user = vultrTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $server = Server::factory()->vultr()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionVultrServerJob($server))->handle();

    $server->refresh();

    expect($server->status)->toBe(Server::STATUS_ERROR);
    expect($server->meta['provision_error']['provider'] ?? null)->toBe('vultr');
    expect($server->meta['provision_error']['message'] ?? '')->toContain('Invalid API token');
});

test('poll vultr ip job sets ready when public ip available', function () {
    Queue::fake();

    Http::fake([
        'https://api.vultr.com/v2/instances/vps-9001' => Http::response([
            'instance' => [
                'id' => 'vps-9001',
                'main_ip' => '203.0.113.10',
            ],
        ], 200),
    ]);

    $user = vultrTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $server = Server::factory()->vultr()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'vps-9001',
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new PollVultrIpJob($server))->handle();

    $server->refresh();

    expect($server->ip_address)->toBe('203.0.113.10');
    expect($server->status)->toBe(Server::STATUS_READY);

    Queue::assertPushed(WaitForServerSshReadyJob::class);
});

test('delete server action destroys vultr instance', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/vps-9001' => Http::response([], 204),
    ]);

    $user = vultrTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $server = Server::factory()->vultr()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'vps-9001',
    ]);

    app(DeleteServerAction::class)->execute($server, $user);

    $this->assertModelMissing($server);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.vultr.com/v2/instances/vps-9001');
});

test('vultr disabled via config hides provider from create cards', function () {
    config(['server_providers.enabled.vultr' => false]);

    $user = vultrTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('vultr');
});

test('fake cloud provision intercepts vultr servers in testing', function () {
    config([
        'server_provision_fake.env_flag' => true,
        'server_provision_fake.allowed_environments' => ['testing'],
    ]);

    $user = vultrTestUser();
    $org = $user->currentOrganization();

    $server = Server::factory()->vultr()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    expect(FakeCloudProvision::shouldInterceptVmProvision($server))->toBeTrue();
});

function vultrFormComponent(): Component
{
    return new class extends Component
    {
        public function render(): string
        {
            return '';
        }
    };
}
