<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites;

use App\Livewire\Sites\Settings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ServerlessRuntimeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $serverlessMeta
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function functionSite(array $serverlessMeta = []): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_ACTIVE,
            'meta' => [
                'runtime_profile' => 'digitalocean_functions_web',
                'serverless' => array_merge([
                    'runtime' => 'nodejs:20',
                    'entrypoint' => 'main',
                    'action_name' => 'acme-api',
                    'last_revision_id' => '0.0.4',
                    'action_url' => 'https://faas-nyc1.doserverless.co/api/v1/web/fn-abc/default/acme-api',
                ], $serverlessMeta),
            ],
        ]);

        return [$user, $server, $site];
    }

    public function test_runtime_tab_renders_the_serverless_control_surface(): void
    {
        [$user, $server, $site] = $this->functionSite();
        Http::fake();

        Livewire::actingAs($user)
            ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
            ->assertOk()
            ->assertSee('Execution profile')
            ->assertSee('Resource limits')
            ->assertSee('Concurrency')
            ->assertSee('Cold starts')
            // The VM runtime partial must NOT render for a function site.
            ->assertDontSee('Site processes')
            ->assertDontSee('Working directory');
    }

    public function test_it_hydrates_limit_fields_from_stored_config(): void
    {
        [$user, $server, $site] = $this->functionSite([
            'limits' => ['memory' => 1024, 'timeout' => 90000, 'concurrency' => 6],
        ]);
        Http::fake();

        Livewire::actingAs($user)
            ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
            ->assertSet('serverless_memory', 1024)
            ->assertSet('serverless_timeout_ms', 90000)
            ->assertSet('serverless_concurrency', 6);
    }

    public function test_saving_persists_limits_to_site_meta(): void
    {
        [$user, $server, $site] = $this->functionSite();
        Http::fake();

        Livewire::actingAs($user)
            ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
            ->set('serverless_memory', 1024)
            ->set('serverless_timeout_ms', 120000)
            ->set('serverless_concurrency', 8)
            ->call('saveServerlessRuntime')
            ->assertHasNoErrors();

        $this->assertSame([
            'memory' => 1024,
            'timeout' => 120000,
            'concurrency' => 8,
        ], $site->fresh()->serverlessLimits());
    }

    public function test_it_rejects_an_unsupported_memory_value(): void
    {
        [$user, $server, $site] = $this->functionSite();
        Http::fake();

        Livewire::actingAs($user)
            ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
            ->set('serverless_memory', 999)
            ->call('saveServerlessRuntime')
            ->assertHasErrors('serverless_memory');
    }

    public function test_it_rejects_a_timeout_above_the_platform_ceiling(): void
    {
        [$user, $server, $site] = $this->functionSite();
        Http::fake();

        Livewire::actingAs($user)
            ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
            ->set('serverless_timeout_ms', 5_000_000)
            ->call('saveServerlessRuntime')
            ->assertHasErrors('serverless_timeout_ms');
    }

    public function test_it_rejects_concurrency_above_the_ceiling(): void
    {
        [$user, $server, $site] = $this->functionSite();
        Http::fake();

        Livewire::actingAs($user)
            ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
            ->set('serverless_concurrency', 999)
            ->call('saveServerlessRuntime')
            ->assertHasErrors('serverless_concurrency');
    }

    public function test_it_flags_a_pending_redeploy_when_saved_limits_differ_from_deployed(): void
    {
        [$user, $server, $site] = $this->functionSite([
            'limits' => ['memory' => 1024, 'timeout' => 60000, 'concurrency' => 1],
            'deployed_limits' => ['memory' => 512, 'timeout' => 60000, 'concurrency' => 1],
        ]);
        Http::fake();

        Livewire::actingAs($user)
            ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'runtime'])
            ->assertSee('Redeploy now');
    }
}
