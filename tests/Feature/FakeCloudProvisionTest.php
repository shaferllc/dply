<?php

namespace Tests\Feature;

use App\Jobs\PollDropletIpJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FakeCloudProvisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'server_provision_fake.env_flag' => true,
            'server_provision_fake.allowed_environments' => ['testing'],
        ]);
    }

    public function test_fake_cloud_disabled_when_env_flag_off(): void
    {
        config(['server_provision_fake.env_flag' => false]);

        $this->assertFalse(FakeCloudProvision::enabled());
    }

    public function test_fake_cloud_disabled_when_environment_not_allowed(): void
    {
        config([
            'server_provision_fake.env_flag' => true,
            'server_provision_fake.allowed_environments' => ['local'],
        ]);

        $this->assertFalse(FakeCloudProvision::enabled());
    }

    public function test_fake_cloud_enabled_in_testing_when_configured(): void
    {
        $this->assertTrue(FakeCloudProvision::enabled());
    }

    public function test_provision_digital_ocean_job_skips_cloud_api_and_queues_ssh_ready(): void
    {
        Queue::fake();

        config([
            'server_provision_fake.ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNza2FzZWFlndm\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'token'],
        ]);

        $server = Server::factory()->digitalOcean()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider_credential_id' => $credential->id,
            'status' => Server::STATUS_PENDING,
            'meta' => [
                'server_role' => 'application',
                'install_profile' => 'laravel_app',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);

        (new ProvisionDigitalOceanDropletJob($server))->handle();

        $server->refresh();

        $this->assertSame(FakeCloudProvision::sentinelProviderId(), $server->provider_id);
        $this->assertSame(Server::STATUS_READY, $server->status);
        $this->assertNotSame('', (string) $server->ssh_private_key);

        Queue::assertPushed(WaitForServerSshReadyJob::class);
    }

    public function test_poll_droplet_job_noops_for_fake_server_without_double_dispatch(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'token'],
        ]);

        $server = Server::factory()->digitalOcean()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider_credential_id' => $credential->id,
            'provider_id' => FakeCloudProvision::sentinelProviderId(),
            'ip_address' => '127.0.0.1',
            'status' => Server::STATUS_READY,
            'meta' => ['server_role' => 'application'],
        ]);

        (new PollDropletIpJob($server))->handle();

        Queue::assertNotPushed(WaitForServerSshReadyJob::class);
    }
}
