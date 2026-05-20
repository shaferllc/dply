<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProvisionServerlessHostJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisionServerlessHostJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeHost(array $serverMeta = []): Server
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $credential = ProviderCredential::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider' => 'digitalocean',
            'name' => 'DO main',
            'credentials' => ['token' => 'dop_v1_test'],
        ]);

        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'region' => 'nyc1',
            'status' => Server::STATUS_PENDING,
            'meta' => array_merge(['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS], $serverMeta),
        ]);

        Site::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'server_id' => $server->id,
            'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
        ]);

        return $server;
    }

    private function fakeNamespaceApi(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/functions/namespaces' => Http::response([
                'namespace' => [
                    'api_host' => 'https://faas-nyc1.doserverless.co',
                    'namespace' => 'fn-abc123',
                    'key' => 'abc:secret',
                    'region' => 'nyc1',
                ],
            ], 200),
        ]);
    }

    public function test_provisions_namespace_metadata_and_marks_host_ready(): void
    {
        Bus::fake();
        $this->fakeNamespaceApi();
        $server = $this->makeHost();

        (new ProvisionServerlessHostJob($server->id))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_READY, $server->status);
        $this->assertSame('https://faas-nyc1.doserverless.co', $server->meta['digitalocean_functions']['api_host']);
        $this->assertSame('fn-abc123', $server->meta['digitalocean_functions']['namespace']);
        $this->assertSame('abc:secret', $server->meta['digitalocean_functions']['access_key']);
    }

    public function test_dispatches_a_deploy_for_each_configured_function(): void
    {
        Bus::fake();
        $this->fakeNamespaceApi();
        $server = $this->makeHost();

        (new ProvisionServerlessHostJob($server->id))->handle();

        Bus::assertDispatchedTimes(RunSiteDeploymentJob::class, 1);
    }

    public function test_is_idempotent_when_namespace_already_provisioned(): void
    {
        Bus::fake();
        Http::fake(); // any call would 200-empty; assert none happens
        $server = $this->makeHost([
            'digitalocean_functions' => [
                'api_host' => 'https://faas-nyc1.doserverless.co',
                'namespace' => 'fn-existing',
                'access_key' => 'k:s',
            ],
        ]);

        (new ProvisionServerlessHostJob($server->id))->handle();

        Http::assertNothingSent();
        // Still redeploys the configured functions.
        Bus::assertDispatched(RunSiteDeploymentJob::class);
    }

    public function test_marks_host_errored_when_the_api_call_fails(): void
    {
        Bus::fake();
        Http::fake([
            'api.digitalocean.com/v2/functions/namespaces' => Http::response(['message' => 'nope'], 500),
        ]);
        $server = $this->makeHost();

        (new ProvisionServerlessHostJob($server->id))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_ERROR, $server->status);
        $this->assertArrayNotHasKey('digitalocean_functions', $server->meta);
        Bus::assertNotDispatched(RunSiteDeploymentJob::class);
    }
}
