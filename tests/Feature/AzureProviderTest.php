<?php

namespace Tests\Feature\AzureProviderTest;

use App\Actions\Servers\DeleteServerAction;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Jobs\PollAzureIpJob;
use App\Jobs\ProvisionAzureServerJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerProviderGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Component;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['server_providers.enabled.azure' => true]);
    Feature::define('provider.azure', fn (): bool => true);
    Feature::flushCache();
});

function azureTestUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function fakeAzureAuthAndCatalog(): void
{
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/oauth2/token')) {
            return Http::response([
                'access_token' => 'azure_token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200);
        }

        if (str_contains($url, '/providers/Microsoft.Compute/locations/') && str_contains($url, '/vmSizes')) {
            return Http::response([
                'value' => [
                    ['name' => 'Standard_B1s', 'numberOfCores' => 1, 'memoryInMB' => 1024],
                ],
            ], 200);
        }

        if (str_contains($url, '/subscriptions/') && str_contains($url, '/locations')) {
            return Http::response([
                'value' => [
                    ['name' => 'eastus', 'displayName' => 'East US'],
                ],
            ], 200);
        }

        return Http::response([], 404);
    });
}

test('list server provider cards includes azure when enabled', function () {
    $user = azureTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->toContain('azure');
});

test('azure is omitted when pennant flag is off', function () {
    Feature::define('provider.azure', fn (): bool => false);
    Feature::flushCache();

    $user = azureTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('azure');
    expect(ServerProviderGate::enabled('azure'))->toBeFalse();
});

test('azure credential store validates service principal credentials', function () {
    fakeAzureAuthAndCatalog();

    $user = azureTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('azure_name', 'Production Azure')
        ->set('azure_tenant_id', 'tenant-123')
        ->set('azure_client_id', 'client-123')
        ->set('azure_client_secret', 'secret-123')
        ->set('azure_subscription_id', 'sub-123')
        ->call('storeAzure')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'azure',
        'name' => 'Production Azure',
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://login.microsoftonline.com/tenant-123/oauth2/token');
});

test('resolve azure catalog returns locations and vm sizes', function () {
    fakeAzureAuthAndCatalog();

    $user = azureTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'azure',
        'credentials' => [
            'tenant_id' => 'tenant-123',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
            'subscription_id' => 'sub-123',
        ],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'azure', (string) $credential->id, 'eastus');

    expect($catalog['regions'])->toHaveCount(1);
    expect($catalog['regions'][0]['value'])->toBe('eastus');
    expect($catalog['sizes'])->toHaveCount(1);
    expect($catalog['sizes'][0]['value'])->toBe('Standard_B1s');
});

test('store server from create form dispatches azure provision job', function () {
    Queue::fake();

    $user = azureTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'azure',
        'credentials' => [
            'tenant_id' => 'tenant-123',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
            'subscription_id' => 'sub-123',
        ],
    ]);

    $form = new ServerCreateForm(azureFormComponent(), 'form');
    $form->type = 'azure';
    $form->name = 'app-server';
    $form->provider_credential_id = (string) $credential->id;
    $form->region = 'eastus';
    $form->size = 'Standard_B1s';
    $form->install_profile = 'laravel_app';
    $form->server_role = 'application';
    $form->webserver = 'nginx';
    $form->php_version = '8.3';
    $form->database = 'mysql84';
    $form->cache_service = 'redis';

    $server = StoreServerFromCreateForm::run($user, $org, $form);

    expect($server->provider)->toBe(ServerProvider::Azure);
    expect($server->status)->toBe(Server::STATUS_PENDING);

    Queue::assertPushed(ProvisionAzureServerJob::class, fn (ProvisionAzureServerJob $job) => $job->server->is($server));
});

test('provision azure job creates vm resources and queues ip poll', function () {
    Queue::fake();

    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/token' => Http::response([
            'access_token' => 'azure_token',
            'expires_in' => 3600,
        ], 200),
        'https://management.azure.com/subscriptions/*/resourceGroups/*/providers/Microsoft.Network/publicIPAddresses/*' => Http::response([
            'id' => '/subscriptions/sub-123/resourceGroups/dply/providers/Microsoft.Network/publicIPAddresses/app-server-pip-abc123',
        ], 201),
        'https://management.azure.com/subscriptions/*/resourceGroups/*/providers/Microsoft.Network/networkInterfaces/*' => Http::response([
            'id' => '/subscriptions/sub-123/resourceGroups/dply/providers/Microsoft.Network/networkInterfaces/app-server-nic-abc123',
        ], 201),
        'https://management.azure.com/subscriptions/*/resourceGroups/*/providers/Microsoft.Compute/virtualMachines/*' => Http::response([
            'id' => '/subscriptions/sub-123/resourceGroups/dply/providers/Microsoft.Compute/virtualMachines/app-server',
        ], 201),
    ]);

    $user = azureTestUser();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'azure',
        'credentials' => [
            'tenant_id' => 'tenant-123',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
            'subscription_id' => 'sub-123',
        ],
    ]);

    $server = Server::factory()->azure()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => ['server_role' => 'application'],
    ]);

    (new ProvisionAzureServerJob($server))->handle();

    $server->refresh();

    expect($server->status)->toBe(Server::STATUS_PROVISIONING);
    expect($server->provider_id)->not->toBe('');
    expect($server->meta['azure']['resource_group'] ?? null)->toBe('dply');
    expect($server->meta['azure']['pip_name'] ?? null)->not->toBe('');

    Queue::assertPushed(PollAzureIpJob::class);
});

test('poll azure ip job marks server ready and dispatches stack setup', function () {
    Queue::fake();

    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/token' => Http::response([
            'access_token' => 'azure_token',
            'expires_in' => 3600,
        ], 200),
        'https://management.azure.com/subscriptions/*/resourceGroups/*/providers/Microsoft.Network/publicIPAddresses/*' => Http::response([
            'properties' => ['ipAddress' => '203.0.113.44'],
        ], 200),
    ]);

    $user = azureTestUser();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'azure',
        'credentials' => [
            'tenant_id' => 'tenant-123',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
            'subscription_id' => 'sub-123',
        ],
    ]);

    $server = Server::factory()->azure()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'app-server-abc123',
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => [
            'server_role' => 'application',
            'azure' => [
                'resource_group' => 'dply',
                'pip_name' => 'app-server-pip-abc123',
            ],
        ],
    ]);

    (new PollAzureIpJob($server))->handle();

    $server->refresh();
    expect($server->ip_address)->toBe('203.0.113.44');
    expect($server->status)->toBe(Server::STATUS_READY);

    Queue::assertPushed(WaitForServerSshReadyJob::class);
});

test('delete server action destroys azure vm', function () {
    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/token' => Http::response([
            'access_token' => 'azure_token',
            'expires_in' => 3600,
        ], 200),
        'https://management.azure.com/subscriptions/*/resourceGroups/*/providers/Microsoft.Compute/virtualMachines/*' => Http::response([], 202),
    ]);

    $user = azureTestUser();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'azure',
        'credentials' => [
            'tenant_id' => 'tenant-123',
            'client_id' => 'client-123',
            'client_secret' => 'secret-123',
            'subscription_id' => 'sub-123',
        ],
    ]);

    $server = Server::factory()->azure()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'app-server-abc123',
        'meta' => [
            'azure' => [
                'resource_group' => 'dply',
                'vm_name' => 'app-server-abc123',
            ],
        ],
    ]);

    app(DeleteServerAction::class)->execute($server, $user);

    $this->assertModelMissing($server);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/resourceGroups/dply/providers/Microsoft.Compute/virtualMachines/app-server-abc123'));
});

function azureFormComponent(): Component
{
    return new class extends Component
    {
        public function render(): string
        {
            return '';
        }
    };
}
