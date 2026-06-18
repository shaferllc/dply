<?php

namespace Tests\Feature\AwsEc2ProviderTest;

use App\Actions\Servers\DeleteServerAction;
use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Jobs\PollAwsEc2IpJob;
use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Create\StepType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Modules\Cloud\Services\AwsEc2Service;
use App\Modules\Cloud\Services\AwsEc2ServiceFactory;
use App\Support\Servers\FakeCloudProvision;
use Aws\Ec2\Ec2Client;
use Aws\Result;
use Aws\Ssm\SsmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Component;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'server_providers.enabled.aws' => true,
        'services.aws.default_image' => 'ami-test',
        'services.aws.security_group_id' => 'sg-test',
    ]);
    Feature::define('provider.aws', fn (): bool => true);
    Feature::flushCache();
});

afterEach(function () {
    app()->forgetInstance(AwsEc2ServiceFactory::class);
    Mockery::close();
});

function awsEc2TestUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('list server provider cards includes aws when enabled', function () {
    $user = awsEc2TestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->toContain('aws');
});

test('credentials nav includes aws when enabled', function () {
    $nav = CredentialsIndex::credentialProviderNav();
    $allIds = [];
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $allIds[] = $item['id'];
        }
    }

    expect($allIds)->toContain('aws');
});

test('server create wizard step shows aws in provider mode copy', function () {
    $user = awsEc2TestUser();

    Livewire::actingAs($user)
        ->test(StepType::class)
        ->assertSee('AWS', false);
});

test('aws credential store validates via ec2 describe regions', function () {
    $ec2 = Mockery::mock(Ec2Client::class);
    $ec2->shouldReceive('describeRegions')
        ->once()
        ->with(['AllRegions' => false]);

    $user = awsEc2TestUser();

    $credential = ProviderCredential::factory()->make([
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'aws',
        'credentials' => [
            'access_key_id' => 'AKIA1234567890',
            'secret_access_key' => 'verysecret',
        ],
    ]);

    bindMockAwsEc2Service($credential, $ec2);

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('aws_access_key_id', 'AKIA1234567890')
        ->set('aws_secret_access_key', 'verysecret')
        ->set('aws_name', 'Production AWS')
        ->call('storeAws')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'aws',
        'name' => 'Production AWS',
    ]);
});

test('resolve aws catalog returns regions and instance types', function () {
    $user = awsEc2TestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'credentials' => [
            'access_key_id' => 'AKIAFAKE',
            'secret_access_key' => 'fake-secret',
        ],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'aws', (string) $credential->id, '');

    expect($catalog['regions'])->not->toBeEmpty();
    expect($catalog['sizes'])->not->toBeEmpty();
    expect($catalog['sizes'][0]['value'])->toBe('t3.micro');
    expect($catalog['size_label'])->toBe('Instance type');
});

test('store server from create form dispatches aws ec2 provision job', function () {
    Queue::fake();

    $user = awsEc2TestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'credentials' => [
            'access_key_id' => 'AKIAFAKE',
            'secret_access_key' => 'fake-secret',
        ],
    ]);

    $form = new ServerCreateForm(awsEc2FormComponent(), 'form');
    $form->type = 'aws';
    $form->name = 'app-server';
    $form->provider_credential_id = (string) $credential->id;
    $form->region = 'us-east-1';
    $form->size = 't3.micro';
    $form->install_profile = 'laravel_app';
    $form->server_role = 'application';
    $form->webserver = 'nginx';
    $form->php_version = '8.3';
    $form->database = 'mysql84';
    $form->cache_service = 'redis';

    $server = StoreServerFromCreateForm::run($user, $org, $form);

    expect($server->provider)->toBe(ServerProvider::Aws);
    expect($server->status)->toBe(Server::STATUS_PENDING);

    Queue::assertPushed(ProvisionAwsEc2ServerJob::class, fn (ProvisionAwsEc2ServerJob $job) => $job->server->is($server));
});

test('provision aws ec2 job creates key pair and instance', function () {
    Queue::fake();

    $ec2 = Mockery::mock(Ec2Client::class);
    $ec2->shouldReceive('createKeyPair')
        ->once()
        ->andReturn(new Result([
            'KeyName' => 'dply-key',
            'KeyMaterial' => "-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----",
        ]));
    $ec2->shouldReceive('runInstances')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return $args['ImageId'] === 'ami-test'
                && $args['InstanceType'] === 't3.micro'
                && ($args['NetworkInterfaces'][0]['Groups'] ?? []) === ['sg-test'];
        }))
        ->andReturn(new Result([
            'Instances' => [['InstanceId' => 'i-abc123']],
        ]));

    $user = awsEc2TestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'credentials' => [
            'access_key_id' => 'AKIAFAKE',
            'secret_access_key' => 'fake-secret',
        ],
    ]);

    $server = Server::factory()->aws()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    bindMockAwsEc2Service($credential, $ec2);

    (new ProvisionAwsEc2ServerJob($server))->handle();

    $server->refresh();

    expect($server->provider_id)->toBe('i-abc123');
    expect($server->status)->toBe(Server::STATUS_PROVISIONING);
    expect($server->ssh_private_key)->not->toBeNull();
    expect($server->meta['key_name'] ?? null)->not->toBeNull();

    Queue::assertPushed(PollAwsEc2IpJob::class);
});

test('provision aws ec2 job surfaces api errors on server meta', function () {
    $ec2 = Mockery::mock(Ec2Client::class);
    $ec2->shouldReceive('createKeyPair')
        ->once()
        ->andThrow(new \RuntimeException('UnauthorizedOperation: You are not authorized to perform ec2:CreateKeyPair'));

    $user = awsEc2TestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'credentials' => [
            'access_key_id' => 'AKIAFAKE',
            'secret_access_key' => 'fake-secret',
        ],
    ]);

    $server = Server::factory()->aws()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
    ]);

    config(['server_provision_fake.env_flag' => false]);

    bindMockAwsEc2Service($credential, $ec2);

    (new ProvisionAwsEc2ServerJob($server))->handle();

    $server->refresh();

    expect($server->status)->toBe(Server::STATUS_ERROR);
    expect($server->meta['provision_error']['provider'] ?? null)->toBe('aws');
    expect($server->meta['provision_error']['message'] ?? '')->toContain('UnauthorizedOperation');
});

test('poll aws ec2 ip job sets ready when public ip available', function () {
    Queue::fake();

    $ec2 = Mockery::mock(Ec2Client::class);
    $ec2->shouldReceive('describeInstances')
        ->once()
        ->with(['InstanceIds' => ['i-abc123']])
        ->andReturn(new Result([
            'Reservations' => [[
                'Instances' => [[
                    'InstanceId' => 'i-abc123',
                    'PublicIpAddress' => '203.0.113.10',
                    'State' => ['Name' => 'running'],
                ]],
            ]],
        ]));

    $user = awsEc2TestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'credentials' => [
            'access_key_id' => 'AKIAFAKE',
            'secret_access_key' => 'fake-secret',
        ],
    ]);

    $server = Server::factory()->aws()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'i-abc123',
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => "-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----",
        'meta' => ['server_role' => 'application'],
    ]);

    config(['server_provision_fake.env_flag' => false]);

    bindMockAwsEc2Service($credential, $ec2);

    (new PollAwsEc2IpJob($server))->handle();

    $server->refresh();

    expect($server->ip_address)->toBe('203.0.113.10');
    expect($server->status)->toBe(Server::STATUS_READY);

    Queue::assertPushed(WaitForServerSshReadyJob::class);
});

test('delete server action terminates aws ec2 instance and key pair', function () {
    $ec2 = Mockery::mock(Ec2Client::class);
    $ec2->shouldReceive('terminateInstances')
        ->once()
        ->with(['InstanceIds' => ['i-abc123']]);
    $ec2->shouldReceive('deleteKeyPair')
        ->once()
        ->with(['KeyName' => 'dply-key']);

    $user = awsEc2TestUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'credentials' => [
            'access_key_id' => 'AKIAFAKE',
            'secret_access_key' => 'fake-secret',
        ],
    ]);

    $server = Server::factory()->aws()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => 'i-abc123',
        'meta' => ['key_name' => 'dply-key'],
    ]);

    bindMockAwsEc2Service($credential, $ec2);

    app(DeleteServerAction::class)->execute($server, $user);

    $this->assertModelMissing($server);
});

test('aws disabled via config hides provider from create cards', function () {
    config(['server_providers.enabled.aws' => false]);

    $user = awsEc2TestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('aws');
});

test('aws disabled via pennant hides provider from create cards', function () {
    Feature::define('provider.aws', fn (): bool => false);
    Feature::flushCache();

    $user = awsEc2TestUser();
    $org = $user->currentOrganization();

    $ids = array_column(ListServerProviderCards::run($org), 'id');

    expect($ids)->not->toContain('aws');
});

test('fake cloud provision intercepts aws ec2 servers in testing', function () {
    config([
        'server_provision_fake.env_flag' => true,
        'server_provision_fake.allowed_environments' => ['testing'],
    ]);

    $user = awsEc2TestUser();
    $org = $user->currentOrganization();

    $server = Server::factory()->aws()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    expect(FakeCloudProvision::shouldInterceptVmProvision($server))->toBeTrue();
});

function awsEc2FormComponent(): Component
{
    return new class extends Component
    {
        public function render(): string
        {
            return '';
        }
    };
}

function bindMockAwsEc2Service(ProviderCredential $credential, Ec2Client $ec2): void
{
    $ssm = Mockery::mock(SsmClient::class);
    $ssm->shouldReceive('getParameter')
        ->andReturn(new Result(['Parameter' => ['Value' => 'ami-test']]));

    $service = (new AwsEc2Service($credential, 'us-east-1'))
        ->withClient($ec2)
        ->withSsmClient($ssm);

    app()->instance(AwsEc2ServiceFactory::class, new class($service) extends AwsEc2ServiceFactory
    {
        public function __construct(private AwsEc2Service $service) {}

        public function make(ProviderCredential $credential, ?string $region = null): AwsEc2Service
        {
            return $this->service;
        }
    });
}
