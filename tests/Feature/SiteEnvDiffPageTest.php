<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteEnvReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteEnvDiffPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_in_sync_when_cache_matches_server(): void
    {
        [$user, $server, $site] = $this->makeUserSite(['env_file_content' => 'A=one']);
        $this->bindFakeReader("A=one\n");

        $response = $this->actingAs($user)->get(route('sites.env-diff', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertOk()
            ->assertSee('in sync', false);
    }

    public function test_categorizes_keys_into_three_buckets(): void
    {
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => "CACHE_ONLY=c\nSHARED=cache-value",
        ]);
        $this->bindFakeReader("SERVER_ONLY=s\nSHARED=server-value\n");

        $response = $this->actingAs($user)->get(route('sites.env-diff', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertOk()
            ->assertSee('CACHE_ONLY')
            ->assertSee('SERVER_ONLY')
            ->assertSee('SHARED')
            ->assertSee('Differs in value');
    }

    public function test_masks_values_by_default(): void
    {
        [$user, $server, $site] = $this->makeUserSite([
            'env_file_content' => 'API_KEY=super-cache-secret',
        ]);
        $this->bindFakeReader("API_KEY=super-server-secret\n");

        $response = $this->actingAs($user)->get(route('sites.env-diff', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertOk()
            ->assertDontSee('super-cache-secret')
            ->assertDontSee('super-server-secret')
            ->assertSee('•');
    }

    public function test_unsupported_runtime_renders_short_circuit(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        // App-Platform host has no server-side .env to diff against.
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $response = $this->actingAs($user)->get(route('sites.env-diff', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertOk()
            ->assertSee('does not have a server-side .env file', false);
    }

    /**
     * @param  array<string, mixed>  $siteAttrs
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function makeUserSite(array $siteAttrs = []): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ssh_private_key' => 'fake-key',
        ]);
        $site = Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ], $siteAttrs));

        return [$user, $server, $site];
    }

    private function bindFakeReader(string $serverEnv): void
    {
        $this->app->bind(SiteEnvReader::class, fn () => new class($serverEnv) extends SiteEnvReader
        {
            public function __construct(private readonly string $payload)
            {
                // Bypass parent constructor; the wrapper is unused for the fake.
            }

            public function read(Site $site): string
            {
                return $this->payload;
            }
        });
    }
}
