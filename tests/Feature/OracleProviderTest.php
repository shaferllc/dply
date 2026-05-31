<?php

namespace Tests\Feature\OracleProviderTest;

use App\Actions\Servers\DeleteServerAction;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Jobs\PollOracleIpJob;
use App\Jobs\ProvisionOracleServerJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Forms\ServerCreateForm;
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
    config([
        'server_providers.enabled.oracle' => true,
        'services.oracle.default_image_id' => 'ocid1.image.oc1.test-image',
    ]);
    Feature::define('provider.oracle', fn (): bool => true);
    Feature::flushCache();
});

function oracleTestUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

/**
 * @return array<string, string>
 */
function oracleCredentialPayload(): array
{
    return [
        'tenancy_ocid' => 'ocid1.tenancy.oc1..exampleuniqueID',
        'user_ocid' => 'ocid1.user.oc1..exampleuniqueID',
        'fingerprint' => '12:34:56:78:90:ab:cd:ef:12:34:56:78:90:ab:cd:ef',
        'private_key' => oracleTestPrivateKey(),
        'region' => 'us-ashburn-1',
        'compartment_id' => 'ocid1.compartment.oc1..exampleuniqueID',
    ];
}

function oracleTestPrivateKey(): string
{
    static $privateKey = null;
    if (is_string($privateKey) && $privateKey !== '') {
        return $privateKey;
    }

    $resource = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    if ($resource === false) {
        throw new \RuntimeException('Unable to generate test private key.');
    }

    $exported = '';
    openssl_pkey_export($resource, $exported);
    openssl_pkey_free($resource);
    $privateKey = $exported;

    return $privateKey;
}

test('list server provider cards includes oracle when enabled', function () {
    $user = oracleTestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->toContain('oracle');
});

test('credentials nav includes oracle when enabled', function () {
    $nav = CredentialsIndex::credentialProviderNav();
    $allIds = [];
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $allIds[] = $item['id'];
        }
    }

    expect($allIds)->toContain('oracle');
});

test('oracle credential store validates via availability domain api', function () {
    Http::fake([
        'https://identity.us-ashburn-1.oraclecloud.com/20160918/availabilityDomains*' => Http::response([
            ['name' => 'kIdk:US-ASHBURN-AD-1'],
        ], 200),
    ]);

    $user = oracleTestUser();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('oracle_name', 'Production OCI')
        ->set('oracle_tenancy_ocid', 'ocid1.tenancy.oc1..exampleuniqueID')
        ->set('oracle_user_ocid', 'ocid1.user.oc1..exampleuniqueID')
        ->set('oracle_fingerprint', '12:34:56:78:90:ab:cd:ef:12:34:56:78:90:ab:cd:ef')
        ->set('oracle_private_key', oracleTestPrivateKey())
        ->set('oracle_region', 'us-ashburn-1')
        ->set('oracle_compartment_id', 'ocid1.compartment.oc1..exampleuniqueID')
        ->call('storeOracle')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'oracle',
        'name' => 'Production OCI',
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://identity.us-ashburn-1.oraclecloud.com/20160918/availabilityDomains?compartmentId=ocid1.compartment.oc1..exampleuniqueID'
        && str_contains((string) $request->header('Authorization')[0], 'Signature version="1"'));
});

test('resolve oracle catalog returns regions and shapes', function () {
    Http::fake([
        'https://identity.us-ashburn-1.oraclecloud.com/20160918/availabilityDomains*' => Http::response([
            ['name' => 'kIdk:US-ASHBURN-AD-1'],
        ], 200),
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/shapes*' => Http::response([
            ['shape' => 'VM.Standard.E2.1.Micro', 'ocpus' => 1, 'memoryInGBs' => 1],
        ], 200),
    ]);

    $user = oracleTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'oracle',
        'credentials' => oracleCredentialPayload(),
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'oracle', (string) $credential->id, '');

    expect($catalog['regions'])->not->toBeEmpty();
    expect($catalog['sizes'])->not->toBeEmpty();
    expect($catalog['sizes'][0]['value'])->toBe('VM.Standard.E2.1.Micro');
    expect($catalog['size_label'])->toBe('Shape');
});

test('store server from create form dispatches oracle provision job', function () {
    Queue::fake();

    $user = oracleTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'oracle',
        'credentials' => oracleCredentialPayload(),
    ]);

    $form = new ServerCreateForm(oracleFormComponent(), 'form');
    $form->type = 'oracle';
    $form->name = 'app-server';
    $form->provider_credential_id = (string) $credential->id;
    $form->region = 'us-ashburn-1';
    $form->size = 'VM.Standard.E2.1.Micro';
    $form->install_profile = 'laravel_app';
    $form->server_role = 'application';
    $form->webserver = 'nginx';
    $form->php_version = '8.3';
    $form->database = 'mysql84';
    $form->cache_service = 'redis';

    $server = StoreServerFromCreateForm::run($user, $org, $form);

    expect($server->provider)->toBe(ServerProvider::Oracle);
    expect($server->status)->toBe(Server::STATUS_PENDING);

    Queue::assertPushed(ProvisionOracleServerJob::class, fn (ProvisionOracleServerJob $job) => $job->server->is($server));
});

test('provision oracle job creates instance and queues polling', function () {
    Queue::fake();
    Http::fake([
        'https://identity.us-ashburn-1.oraclecloud.com/20160918/availabilityDomains*' => Http::response([
            ['name' => 'kIdk:US-ASHBURN-AD-1'],
        ], 200),
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/subnets*' => Http::response([
            ['id' => 'ocid1.subnet.oc1..test', 'lifecycleState' => 'AVAILABLE'],
        ], 200),
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances' => Http::response([
            'id' => 'ocid1.instance.oc1..testinstance',
        ], 200),
    ]);

    $user = oracleTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'oracle',
        'credentials' => oracleCredentialPayload(),
    ]);

    $server = Server::factory()->oracle()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new ProvisionOracleServerJob($server))->handle();

    $server->refresh();

    expect($server->provider_id)->toBe('ocid1.instance.oc1..testinstance');
    expect($server->status)->toBe(Server::STATUS_PROVISIONING);
    expect($server->ssh_private_key)->not->toBeNull();

    Queue::assertPushed(PollOracleIpJob::class);
});

test('poll oracle ip job marks server ready when public ip is present', function () {
    Queue::fake();
    Http::fake([
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances/ocid1.instance.oc1..testinstance' => Http::response([
            'id' => 'ocid1.instance.oc1..testinstance',
            'lifecycleState' => 'RUNNING',
        ], 200),
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/vnicAttachments*' => Http::response([
            [
                'vnicId' => 'ocid1.vnic.oc1..test',
                'isPrimary' => true,
                'lifecycleState' => 'ATTACHED',
            ],
        ], 200),
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/vnics/ocid1.vnic.oc1..test' => Http::response([
            'publicIp' => '203.0.113.10',
        ], 200),
    ]);

    $user = oracleTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'oracle',
        'credentials' => oracleCredentialPayload(),
    ]);

    $server = Server::factory()->oracle()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'ocid1.instance.oc1..testinstance',
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    (new PollOracleIpJob($server))->handle();

    $server->refresh();

    expect($server->ip_address)->toBe('203.0.113.10');
    expect($server->status)->toBe(Server::STATUS_READY);

    Queue::assertPushed(WaitForServerSshReadyJob::class);
});

test('delete server action terminates oracle instance', function () {
    Http::fake([
        'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances/ocid1.instance.oc1..testinstance' => Http::response([], 204),
    ]);

    $user = oracleTestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'oracle',
        'credentials' => oracleCredentialPayload(),
    ]);

    $server = Server::factory()->oracle()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'ocid1.instance.oc1..testinstance',
    ]);

    app(DeleteServerAction::class)->execute($server, $user);

    $this->assertModelMissing($server);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances/ocid1.instance.oc1..testinstance');
});

test('fake cloud provision intercepts oracle servers in testing', function () {
    config([
        'server_provision_fake.env_flag' => true,
        'server_provision_fake.allowed_environments' => ['testing'],
    ]);

    $user = oracleTestUser();
    $org = $user->currentOrganization();

    $server = Server::factory()->oracle()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    expect(FakeCloudProvision::shouldInterceptVmProvision($server))->toBeTrue();
});

function oracleFormComponent(): Component
{
    return new class extends Component
    {
        public function render(): string
        {
            return '';
        }
    };
}
