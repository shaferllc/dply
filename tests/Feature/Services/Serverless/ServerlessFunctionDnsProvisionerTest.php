<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Serverless;

use App\Models\Site;
use App\Services\Serverless\ServerlessFunctionDnsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerlessFunctionDnsProvisionerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.digitalocean.testing_domains' => ['dply.host'],
            'services.digitalocean.token' => 'dop_v1_test',
            'services.digitalocean.serverless_function_dns_target' => null,
        ]);
    }

    public function test_it_creates_a_cname_to_the_zone_apex_when_the_record_is_missing(): void
    {
        // Count-agnostic fake: the provisioner lists records several times
        // (wildcard check, purge, post-purge verify, upsert lookup) before
        // the create. Branch on HTTP method instead of a fixed sequence so
        // the test doesn't break when the listing call count changes.
        Http::fake([
            'https://api.digitalocean.com/v2/domains/dply.host/records*' => fn ($request) => $request->method() === 'POST'
                ? Http::response(['domain_record' => ['id' => 99, 'type' => 'CNAME', 'name' => 'laravel-demo', 'data' => 'dply.host.']], 201)
                : Http::response(['domain_records' => []], 200),
        ]);

        $site = Site::factory()->create(['name' => 'Laravel demo']);
        $slug = $site->ensureServerlessProxySlug();

        $status = app(ServerlessFunctionDnsProvisioner::class)->provision($site->fresh());

        $dns = $site->fresh()->serverlessConfig()['dns'] ?? [];
        $this->assertSame('ready', $dns['status']);
        $this->assertSame($slug.'.dply.host', $dns['hostname']);
        $this->assertSame('CNAME', $dns['record_type']);
        $this->assertSame('dply.host.', $dns['record_data']);
        $this->assertStringContainsString('CNAME dply.host.', (string) $status);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/domains/dply.host/records'));
    }

    public function test_it_does_not_create_a_duplicate_when_the_record_already_exists(): void
    {
        $site = Site::factory()->create(['name' => 'Laravel demo']);
        $slug = $site->ensureServerlessProxySlug();

        Http::fake([
            'https://api.digitalocean.com/v2/domains/dply.host/records*' => Http::response([
                'domain_records' => [
                    ['id' => 7, 'type' => 'CNAME', 'name' => $slug, 'data' => 'dply.host.'],
                ],
            ], 200),
        ]);

        app(ServerlessFunctionDnsProvisioner::class)->provision($site->fresh());

        $dns = $site->fresh()->serverlessConfig()['dns'] ?? [];
        $this->assertSame('ready', $dns['status']);
        $this->assertSame(7, $dns['record_id']);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_it_creates_an_a_record_when_an_explicit_ip_target_is_configured(): void
    {
        config(['services.digitalocean.serverless_function_dns_target' => '203.0.113.10']);

        Http::fake([
            'https://api.digitalocean.com/v2/domains/dply.host/records*' => fn ($request) => $request->method() === 'POST'
                ? Http::response(['domain_record' => ['id' => 11, 'type' => 'A', 'name' => 'laravel-demo', 'data' => '203.0.113.10']], 201)
                : Http::response(['domain_records' => []], 200),
        ]);

        $site = Site::factory()->create(['name' => 'Laravel demo']);
        $site->ensureServerlessProxySlug();

        app(ServerlessFunctionDnsProvisioner::class)->provision($site->fresh());

        $dns = $site->fresh()->serverlessConfig()['dns'] ?? [];
        $this->assertSame('A', $dns['record_type']);
        $this->assertSame('203.0.113.10', $dns['record_data']);
    }

    public function test_it_skips_when_no_app_level_token_is_configured(): void
    {
        config(['services.digitalocean.token' => null]);
        Http::fake();

        $site = Site::factory()->create(['name' => 'Laravel demo']);
        $site->ensureServerlessProxySlug();

        $status = app(ServerlessFunctionDnsProvisioner::class)->provision($site->fresh());

        $this->assertSame('skipped', $site->fresh()->serverlessConfig()['dns']['status'] ?? null);
        $this->assertStringContainsString('skipped', (string) $status);
        Http::assertNothingSent();
    }
}
