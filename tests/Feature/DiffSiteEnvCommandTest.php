<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteEnvReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DiffSiteEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_categorizes_keys_with_a_mocked_server_read(): void
    {
        $site = $this->makeSiteWithEnvSupport(env: "CACHE_ONLY=c\nSHARED=cache-value\nIDENTICAL=same");
        $this->bindFakeReader("SERVER_ONLY=s\nSHARED=server-value\nIDENTICAL=same\n");

        Artisan::call('dply:site:env-diff', [
            'site' => $site->slug,
            '--reveal' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertFalse($decoded['in_sync']);
        $this->assertSame(['CACHE_ONLY'], $decoded['only_in_cache']);
        $this->assertSame(['SERVER_ONLY'], $decoded['only_in_server']);
        $this->assertSame(['SHARED'], array_keys($decoded['differs']));
        $this->assertSame('cache-value', $decoded['differs']['SHARED']['cache']);
        $this->assertSame('server-value', $decoded['differs']['SHARED']['server']);
    }

    public function test_reports_in_sync_when_cache_matches_server(): void
    {
        $site = $this->makeSiteWithEnvSupport(env: 'A=one');
        $this->bindFakeReader("A=one\n");

        Artisan::call('dply:site:env-diff', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['in_sync']);
    }

    public function test_masks_values_in_differs_by_default(): void
    {
        $site = $this->makeSiteWithEnvSupport(env: 'API_KEY=super-cache-secret');
        $this->bindFakeReader("API_KEY=super-server-secret\n");

        Artisan::call('dply:site:env-diff', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertStringNotContainsString('super-cache-secret', json_encode($decoded));
        $this->assertStringNotContainsString('super-server-secret', json_encode($decoded));
        $this->assertStringContainsString('•', $decoded['differs']['API_KEY']['cache']);
    }

    public function test_unsupported_runtime_short_circuits(): void
    {
        $site = $this->makeSiteWithoutEnvSupport();

        Artisan::call('dply:site:env-diff', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['unsupported']);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:env-diff', ['site' => 'nope']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    private function makeSiteWithEnvSupport(string $env = ''): Site
    {
        // Default ServerFactory makes a VM-kind server, which is the only host
        // family that supportsEnvPushToHost(). isReady() and an SSH key are
        // required to even attempt a read; tests bind a fake reader so the
        // bytes never matter, but the early-return guards still need to pass.
        $server = Server::factory()->create([
            'ssh_private_key' => 'fake-key',
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'jobs',
            'env_file_content' => $env,
        ]);
    }

    private function makeSiteWithoutEnvSupport(): Site
    {
        $server = Server::factory()->create([
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'jobs',
        ]);
    }

    private function bindFakeReader(string $serverEnv): void
    {
        $this->app->bind(SiteEnvReader::class, fn () => new class($serverEnv) extends SiteEnvReader
        {
            public function __construct(private readonly string $payload)
            {
                // Bypass parent constructor — we don't need the wrapper for the fake.
            }

            public function read(Site $site): string
            {
                return $this->payload;
            }
        });
    }
}
