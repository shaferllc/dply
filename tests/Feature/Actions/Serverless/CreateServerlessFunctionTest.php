<?php

namespace Tests\Feature\Actions\Serverless;

use App\Actions\Serverless\CreateServerlessFunction;
use App\Jobs\ProvisionServerlessHostJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use Tests\TestCase;

class CreateServerlessFunctionTest extends TestCase
{
    use RefreshDatabase;

    private function handle(array $overrides = []): Site
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

        return app(CreateServerlessFunction::class)->handle($user, $org, array_merge([
            'name' => 'My API',
            'repo' => 'acme/api',
            'branch' => 'main',
            'runtime' => 'nodejs:20',
            'region' => 'nyc1',
            'provider_credential_id' => $credential->id,
        ], $overrides));
    }

    public function test_creates_a_serverless_host_and_function_site(): void
    {
        Bus::fake();

        $site = $this->handle();

        $server = Server::find($site->server_id);
        $this->assertTrue($server->isServerlessHost());
        $this->assertSame(Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS, $server->hostKind());
        // Host starts PENDING — the provision job creates the namespace then
        // marks it READY.
        $this->assertSame(Server::STATUS_PENDING, $server->status);

        $this->assertSame(Site::STATUS_FUNCTIONS_CONFIGURED, $site->status);
        $this->assertSame('acme/api', $site->git_repository_url);
        $this->assertSame('main', $site->git_branch);
        $this->assertSame('nodejs:20', $site->meta['serverless']['runtime']);
        $this->assertSame('digitalocean_functions_web', $site->meta['runtime_profile']);
    }

    public function test_dispatches_the_namespace_provision_job(): void
    {
        Bus::fake();

        $site = $this->handle();

        Bus::assertDispatched(
            ProvisionServerlessHostJob::class,
            fn (ProvisionServerlessHostJob $job) => $job->serverId === $site->server_id,
        );
    }

    public function test_normalizes_a_full_github_url_to_owner_repo(): void
    {
        Bus::fake();

        $site = $this->handle(['repo' => 'https://github.com/acme/widgets.git']);

        $this->assertSame('acme/widgets', $site->git_repository_url);
    }

    public function test_rejects_an_empty_repository(): void
    {
        Bus::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->handle(['repo' => '']);
    }

    public function test_function_site_is_not_billed_until_active(): void
    {
        Bus::fake();
        // Fresh function is `functions_configured`, not `functions_active` —
        // the billing computer only counts active functions.
        $site = $this->handle();

        $this->assertNotSame(Site::STATUS_FUNCTIONS_ACTIVE, $site->status);
    }

    public function test_auto_runtime_is_stored_unset_for_deploy_time_detection(): void
    {
        Bus::fake();
        // `auto` leaves the runtime empty so ServerlessRuntimeDetector picks
        // it from the repo at deploy time; an explicit value is kept verbatim.
        $site = $this->handle(['runtime' => 'auto']);

        $this->assertSame('', $site->meta['serverless']['runtime']);
    }
}
