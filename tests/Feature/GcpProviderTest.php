<?php

namespace Tests\Feature\GcpProviderTest;

use App\Actions\Servers\DeleteServerAction;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Jobs\PollGcpIpJob;
use App\Jobs\ProvisionGcpServerJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Forms\ServerCreateForm;
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
    config([
        'server_providers.enabled.gcp' => true,
        'services.gcp.ssh_user' => 'ubuntu',
    ]);
    Feature::define('provider.gcp', fn (): bool => true);
    Feature::flushCache();
});

function gcpTestUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function gcpServiceAccountArray(string $projectId = 'dply-test'): array
{
    static $privateKey;

    if (! is_string($privateKey) || $privateKey === '') {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($resource === false) {
            throw new \RuntimeException('Unable to generate RSA key for tests.');
        }
        openssl_pkey_export($resource, $privateKey);
    }

    return [
        'type' => 'service_account',
        'project_id' => $projectId,
        'private_key_id' => 'test-key-id',
        'private_key' => $privateKey,
        'client_email' => 'dply-test@'.$projectId.'.iam.gserviceaccount.com',
        'client_id' => '1234567890',
        'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/dply-test%40'.$projectId.'.iam.gserviceaccount.com',
    ];
}

function gcpServiceAccountJson(string $projectId = 'dply-test'): string
{
    return (string) json_encode(gcpServiceAccountArray($projectId), JSON_THROW_ON_ERROR);
}

test('list server provider cards includes gcp when enabled', function () {
    $user = gcpTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->toContain('gcp');
});

test('gcp is omitted when pennant flag is off', function () {
    Feature::define('provider.gcp', fn (): bool => false);
    Feature::flushCache();

    $user = gcpTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('gcp');
    expect(ServerProviderGate::enabled('gcp'))->toBeFalse();
});

test('credentials nav includes gcp when enabled', function () {
    $nav = CredentialsIndex::credentialProviderNav();
    $allIds = [];
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $allIds[] = $item['id'];
        }
    }

    expect($allIds)->toContain('gcp');
});

test('gcp credential store validates service account via compute api', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'gcp_access_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
        'https://compute.googleapis.com/compute/v1/projects/dply-test/zones*' => Http::response([
            'items' => [],
        ], 200),
    ]);

    $user = gcpTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('gcp_name', 'Production GCP')
        ->set('gcp_api_token', gcpServiceAccountJson('dply-test'))
        ->call('storeGcp')
        ->assertHasNoErrors();

    expect(
        ProviderCredential::query()
            ->where('organization_id', $user->currentOrganization()->id)
            ->where('provider', 'gcp')
            ->where('name', 'Production GCP')
            ->exists()
    )->toBeTrue();

    $credential = ProviderCredential::query()->where('provider', 'gcp')->firstOrFail();
    expect($credential->credentials['project_id'] ?? null)->toBe('dply-test');
    expect($credential->credentials['service_account']['type'] ?? null)->toBe('service_account');
});

test('resolve gcp catalog returns zones and machine types', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'gcp_access_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
        'https://compute.googleapis.com/compute/v1/projects/dply-test/zones/us-central1-a/machineTypes*' => Http::response([
            'items' => [
                ['name' => 'e2-micro', 'description' => 'E2 Micro', 'memoryMb' => 1024, 'guestCpus' => 2],
            ],
        ], 200),
        'https://compute.googleapis.com/compute/v1/projects/dply-test/zones*' => Http::response([
            'items' => [
                ['name' => 'us-central1-a', 'description' => 'us-central1-a'],
            ],
        ], 200),
    ]);

    $user = gcpTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'gcp',
        'credentials' => [
            'project_id' => 'dply-test',
            'service_account' => gcpServiceAccountArray('dply-test'),
        ],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'gcp', (string) $credential->id, 'us-central1-a');

    expect($catalog['regions'])->toHaveCount(1);
    expect($catalog['regions'][0]['value'])->toBe('us-central1-a');
    expect($catalog['sizes'])->toHaveCount(1);
    expect($catalog['sizes'][0]['value'])->toBe('e2-micro');
    expect($catalog['size_label'])->toBe('Machine type');
});

test('store server from create form dispatches gcp provision job', function () {
    Queue::fake();

    $user = gcpTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'gcp',
        'credentials' => [
            'project_id' => 'dply-test',
            'service_account' => gcpServiceAccountArray('dply-test'),
        ],
    ]);

    $form = new ServerCreateForm(gcpFormComponent(), 'form');
    $form->type = 'gcp';
    $form->name = 'app-server';
    $form->provider_credential_id = (string) $credential->id;
    $form->region = 'us-central1-a';
    $form->size = 'e2-micro';
    $form->install_profile = 'laravel_app';
    $form->server_role = 'application';
    $form->webserver = 'nginx';
    $form->php_version = '8.3';
    $form->database = 'mysql84';
    $form->cache_service = 'redis';

    $server = StoreServerFromCreateForm::run($user, $org, $form);

    expect($server->provider)->toBe(ServerProvider::Gcp);
    expect($server->status)->toBe(Server::STATUS_PENDING);

    Queue::assertPushed(ProvisionGcpServerJob::class, fn (ProvisionGcpServerJob $job) => $job->server->is($server));
});

test('provision gcp job creates instance and queues ip poll', function () {
    Queue::fake();

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'gcp_access_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
        'https://compute.googleapis.com/compute/v1/projects/dply-test/zones/us-central1-a/instances' => Http::response([
            'name' => 'operation-123',
        ], 200),
    ]);

    $user = gcpTestUser();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'gcp',
        'credentials' => [
            'project_id' => 'dply-test',
            'service_account' => gcpServiceAccountArray('dply-test'),
        ],
    ]);

    $server = Server::factory()->gcp()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'name' => 'App Server',
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionGcpServerJob($server))->handle();

    $server->refresh();

    expect($server->provider_id)->not->toBe('');
    expect($server->status)->toBe(Server::STATUS_PROVISIONING);
    expect($server->ssh_private_key)->not->toBeNull();
    expect($server->ssh_user)->toBe('ubuntu');

    Http::assertSent(fn ($request) => $request->url() === 'https://compute.googleapis.com/compute/v1/projects/dply-test/zones/us-central1-a/instances'
        && (($request->data()['metadata']['items'][0]['value'] ?? '') !== ''));

    Queue::assertPushed(PollGcpIpJob::class);
});

test('poll gcp ip job sets ready when public ip available', function () {
    Queue::fake();

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'gcp_access_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
        'https://compute.googleapis.com/compute/v1/projects/dply-test/zones/us-central1-a/instances/*' => Http::response([
            'status' => 'RUNNING',
            'networkInterfaces' => [
                ['accessConfigs' => [['natIP' => '203.0.113.77']]],
            ],
        ], 200),
    ]);

    $user = gcpTestUser();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'gcp',
        'credentials' => [
            'project_id' => 'dply-test',
            'service_account' => gcpServiceAccountArray('dply-test'),
        ],
    ]);

    $server = Server::factory()->gcp()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'app-server-abc12345',
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new PollGcpIpJob($server))->handle();

    $server->refresh();

    expect($server->ip_address)->toBe('203.0.113.77');
    expect($server->status)->toBe(Server::STATUS_READY);

    Queue::assertPushed(WaitForServerSshReadyJob::class);
});

test('delete server action destroys gcp instance', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'gcp_access_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
        'https://compute.googleapis.com/compute/v1/projects/dply-test/zones/us-central1-a/instances/*' => Http::response([], 200),
    ]);

    $user = gcpTestUser();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'gcp',
        'credentials' => [
            'project_id' => 'dply-test',
            'service_account' => gcpServiceAccountArray('dply-test'),
        ],
    ]);

    $server = Server::factory()->gcp()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'app-server-abc12345',
    ]);

    app(DeleteServerAction::class)->execute($server, $user);

    expect(Server::query()->whereKey($server->id)->exists())->toBeFalse();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/projects/dply-test/zones/us-central1-a/instances/app-server-abc12345'));
});

test('fake cloud provision intercepts gcp servers in testing', function () {
    config([
        'server_provision_fake.env_flag' => true,
        'server_provision_fake.allowed_environments' => ['testing'],
    ]);

    $user = gcpTestUser();
    $org = $user->currentOrganization();

    $server = Server::factory()->gcp()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    expect(FakeCloudProvision::shouldInterceptVmProvision($server))->toBeTrue();
});

function gcpFormComponent(): Component
{
    return new class extends Component
    {
        public function render(): string
        {
            return '';
        }
    };
}
