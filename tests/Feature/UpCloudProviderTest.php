<?php

namespace Tests\Feature\UpCloudProviderTest;

use App\Actions\Servers\DeleteServerAction;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Jobs\PollUpCloudIpJob;
use App\Jobs\ProvisionUpCloudServerJob;
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
    config(['server_providers.enabled.upcloud' => true]);
    Feature::define('provider.upcloud', fn (): bool => true);
    Feature::flushCache();
});

function upcloudTestUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('list server provider cards includes upcloud when enabled', function () {
    $user = upcloudTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->toContain('upcloud');
});

test('credentials nav includes upcloud when enabled', function () {
    $nav = CredentialsIndex::credentialProviderNav();
    $allIds = [];
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $allIds[] = $item['id'];
        }
    }

    expect($allIds)->toContain('upcloud');
});

test('server create wizard step shows provider mode copy', function () {
    $user = upcloudTestUser();

    Livewire::actingAs($user)
        ->test(StepType::class)
        ->assertSee('Provision with a provider', false)
        ->assertSee('DigitalOcean', false);
});

test('upcloud credential store validates token via api', function () {
    Http::fake([
        'https://api.upcloud.com/1.3/zone' => Http::response([
            'zones' => [
                'zone' => [
                    ['id' => 'fi-hel1', 'description' => 'Helsinki #1'],
                ],
            ],
        ], 200),
    ]);

    $user = upcloudTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('upcloud_username', 'uc_test_user')
        ->set('upcloud_password', 'uc_test_pass')
        ->set('upcloud_name', 'Production UpCloud')
        ->call('storeUpCloud')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'upcloud',
        'name' => 'Production UpCloud',
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/zone')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('uc_test_user:uc_test_pass')));
});

test('upcloud credential store rejects invalid credentials', function () {
    Http::fake([
        'https://api.upcloud.com/1.3/zone' => Http::response([
            'error' => 'Unauthorized',
        ], 401),
    ]);

    $user = upcloudTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('upcloud_username', 'bad-user')
        ->set('upcloud_password', 'bad-pass')
        ->call('storeUpCloud')
        ->assertHasErrors('upcloud_username');

    $this->assertDatabaseCount('provider_credentials', 0);
});

test('resolve upcloud catalog returns zones and plans', function () {
    Http::fake([
        'https://api.upcloud.com/1.3/zone' => Http::response([
            'zones' => [
                'zone' => [
                    ['id' => 'fi-hel1', 'description' => 'Helsinki #1'],
                ],
            ],
        ], 200),
        'https://api.upcloud.com/1.3/plan' => Http::response([
            'plans' => [
                'plan' => [
                    [
                        'name' => '1xCPU-1GB',
                        'core_number' => 1,
                        'memory_amount' => 1024,
                        'storage_size' => 25,
                        'price' => '5.00',
                    ],
                ],
            ],
        ], 200),
    ]);

    $user = upcloudTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'upcloud',
        'credentials' => [
            'api_username' => 'uc_test',
            'api_password' => 'uc_pass',
        ],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'upcloud', (string) $credential->id, '');

    expect($catalog['regions'])->toHaveCount(1);
    expect($catalog['regions'][0]['value'])->toBe('fi-hel1');
    expect($catalog['sizes'])->toHaveCount(1);
    expect($catalog['sizes'][0]['value'])->toBe('1xCPU-1GB');
    expect($catalog['region_label'])->toBe('Zone');
    expect($catalog['size_label'])->toBe('Plan');
});

test('store server from create form dispatches upcloud provision job', function () {
    Queue::fake();

    $user = upcloudTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'upcloud',
        'credentials' => [
            'api_username' => 'uc_test',
            'api_password' => 'uc_pass',
        ],
    ]);

    $form = new ServerCreateForm(upcloudFormComponent(), 'form');
    $form->type = 'upcloud';
    $form->name = 'app-server';
    $form->provider_credential_id = (string) $credential->id;
    $form->region = 'fi-hel1';
    $form->size = '1xCPU-1GB';
    $form->install_profile = 'laravel_app';
    $form->server_role = 'application';
    $form->webserver = 'nginx';
    $form->php_version = '8.3';
    $form->database = 'mysql84';
    $form->cache_service = 'redis';

    $server = StoreServerFromCreateForm::run($user, $org, $form);

    expect($server->provider)->toBe(ServerProvider::UpCloud);
    expect($server->status)->toBe(Server::STATUS_PENDING);

    Queue::assertPushed(ProvisionUpCloudServerJob::class, fn (ProvisionUpCloudServerJob $job) => $job->server->is($server));
});

test('provision upcloud job creates server and dispatches poll job', function () {
    Queue::fake();

    Http::fake([
        'https://api.upcloud.com/1.3/server' => Http::response([
            'server' => ['uuid' => '01234567-89ab-cdef-0123-456789abcdef'],
        ], 201),
    ]);

    $user = upcloudTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'upcloud',
        'credentials' => [
            'api_username' => 'uc_test',
            'api_password' => 'uc_pass',
        ],
    ]);

    $server = Server::factory()->upcloud()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionUpCloudServerJob($server))->handle();

    $server->refresh();

    expect($server->provider_id)->toBe('01234567-89ab-cdef-0123-456789abcdef');
    expect($server->status)->toBe(Server::STATUS_PROVISIONING);
    expect($server->ssh_private_key)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.upcloud.com/1.3/server'
        && ($request->data()['server']['zone'] ?? null) === 'fi-hel1'
        && isset($request->data()['server']['login_user']['ssh_keys']['ssh_key']));

    Queue::assertPushed(PollUpCloudIpJob::class);
});

test('provision upcloud job surfaces api errors on server meta', function () {
    Http::fake([
        'https://api.upcloud.com/1.3/server' => Http::response([
            'error' => 'Unauthorized',
        ], 401),
    ]);

    $user = upcloudTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'upcloud',
        'credentials' => [
            'api_username' => 'uc_test',
            'api_password' => 'uc_pass',
        ],
    ]);

    $server = Server::factory()->upcloud()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionUpCloudServerJob($server))->handle();

    $server->refresh();

    expect($server->status)->toBe(Server::STATUS_ERROR);
    expect($server->meta['provision_error']['provider'] ?? null)->toBe('upcloud');
    expect($server->meta['provision_error']['message'] ?? '')->toContain('Unauthorized');
});

test('poll upcloud ip job sets ready when public ip available', function () {
    Queue::fake();

    Http::fake([
        'https://api.upcloud.com/1.3/server/01234567-89ab-cdef-0123-456789abcdef' => Http::response([
            'server' => [
                'uuid' => '01234567-89ab-cdef-0123-456789abcdef',
                'state' => 'started',
                'ip_addresses' => [
                    'ip_address' => [
                        [
                            'access' => 'public',
                            'family' => 'IPv4',
                            'address' => '203.0.113.10',
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $user = upcloudTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'upcloud',
        'credentials' => [
            'api_username' => 'uc_test',
            'api_password' => 'uc_pass',
        ],
    ]);

    $server = Server::factory()->upcloud()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => '01234567-89ab-cdef-0123-456789abcdef',
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new PollUpCloudIpJob($server))->handle();

    $server->refresh();

    expect($server->ip_address)->toBe('203.0.113.10');
    expect($server->status)->toBe(Server::STATUS_READY);

    Queue::assertPushed(WaitForServerSshReadyJob::class);
});

test('delete server action destroys upcloud instance', function () {
    Http::fake([
        'https://api.upcloud.com/1.3/server/01234567-89ab-cdef-0123-456789abcdef' => Http::response([], 200),
    ]);

    $user = upcloudTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'upcloud',
        'credentials' => [
            'api_username' => 'uc_test',
            'api_password' => 'uc_pass',
        ],
    ]);

    $server = Server::factory()->upcloud()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => '01234567-89ab-cdef-0123-456789abcdef',
    ]);

    app(DeleteServerAction::class)->execute($server, $user);

    $this->assertModelMissing($server);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.upcloud.com/1.3/server/01234567-89ab-cdef-0123-456789abcdef');
});

test('upcloud disabled via config hides provider from create cards', function () {
    config(['server_providers.enabled.upcloud' => false]);

    $user = upcloudTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('upcloud');
});

test('upcloud disabled via pennant hides provider from create cards', function () {
    Feature::define('provider.upcloud', fn (): bool => false);
    Feature::flushCache();

    $user = upcloudTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('upcloud');
});

test('fake cloud provision intercepts upcloud servers in testing', function () {
    config([
        'server_provision_fake.env_flag' => true,
        'server_provision_fake.allowed_environments' => ['testing'],
    ]);

    $user = upcloudTestUser();
    $org = $user->currentOrganization();

    $server = Server::factory()->upcloud()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    expect(FakeCloudProvision::shouldInterceptVmProvision($server))->toBeTrue();
});

function upcloudFormComponent(): Component
{
    return new class extends Component
    {
        public function render(): string
        {
            return '';
        }
    };
}
