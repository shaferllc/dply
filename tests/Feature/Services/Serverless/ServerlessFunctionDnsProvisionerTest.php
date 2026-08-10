<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Serverless\ServerlessFunctionDnsProvisionerTest;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessFunctionDnsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Point the serverless apex at a DigitalOcean-hosted zone so the legacy DO
 * path is under test. Production runs the Cloudflare path (see below).
 */
function useDigitalOceanZone(): void
{
    config([
        'serverless.testing_domains' => ['dply.host'],
        'serverless.testing_dns.provider' => 'digitalocean',
        'serverless.testing_dns_target' => null,
        'services.digitalocean.token' => 'dop_v1_test',
        'services.digitalocean.serverless_function_dns_target' => null,
    ]);
}

beforeEach(function () {
    config([
        'serverless.testing_domains' => ['dply-serverless.cloud'],
        'serverless.testing_dns.provider' => 'cloudflare',
        'serverless.testing_dns.cloudflare_api_token' => 'cf_test_token',
        'serverless.testing_dns_target' => null,
        'services.digitalocean.testing_domains' => ['dply.host'],
        'services.digitalocean.token' => 'dop_v1_test',
        'services.digitalocean.serverless_function_dns_target' => null,
    ]);
});

test('every function gets a hostname on the dedicated serverless apex', function () {
    $site = Site::factory()->create(['name' => 'Orders API']);

    expect($site->serverlessFunctionHost())
        ->toBe($site->ensureServerlessProxySlug().'.dply-serverless.cloud');
});

test('the serverless apex defaults to dply-serverless.cloud with no env configured', function () {
    config(['serverless.testing_domains' => []]);

    $site = Site::factory()->create(['name' => 'Orders API']);

    expect($site->serverlessFunctionHost())->toEndWith('.dply-serverless.cloud');
});

test('it creates a cloudflare cname to the apex when the record is missing', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-1', 'name' => 'dply-serverless.cloud']],
        ], 200),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => fn ($request) => $request->method() === 'POST'
            ? Http::response(['success' => true, 'result' => ['id' => 'rec-99', 'type' => 'CNAME']], 200)
            : Http::response(['success' => true, 'result' => []], 200),
    ]);

    $site = Site::factory()->create(['name' => 'Laravel demo']);
    $slug = $site->ensureServerlessProxySlug();

    $status = app(ServerlessFunctionDnsProvisioner::class)->provision($site->fresh());

    $dns = $site->fresh()->serverlessConfig()['dns'] ?? [];
    expect($dns['status'])->toBe('ready');
    expect($dns['hostname'])->toBe($slug.'.dply-serverless.cloud');
    expect($dns['zone'])->toBe('dply-serverless.cloud');
    expect($dns['dns_provider'])->toBe('cloudflare');
    expect($dns['record_type'])->toBe('CNAME');
    expect($dns['record_data'])->toBe('dply-serverless.cloud');
    expect($dns['record_id'])->toBe('rec-99');
    $this->assertStringContainsString('Cloudflare', (string) $status);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/zones/zone-1/dns_records'));
});

test('it treats a cloudflare wildcard record as covering the hostname', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-1', 'name' => 'dply-serverless.cloud']],
        ], 200),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::response([
            'success' => true,
            'result' => [[
                'id' => 'wild-1',
                'type' => 'CNAME',
                'name' => '*.dply-serverless.cloud',
                'content' => 'app.dply.com',
            ]],
        ], 200),
    ]);

    $site = Site::factory()->create(['name' => 'Laravel demo']);
    $site->ensureServerlessProxySlug();

    $status = app(ServerlessFunctionDnsProvisioner::class)->provision($site->fresh());

    $dns = $site->fresh()->serverlessConfig()['dns'] ?? [];
    expect($dns['status'])->toBe('ready');
    expect($dns['covered_by_wildcard'])->toBeTrue();
    expect($dns['wildcard_record_id'])->toBe('wild-1');
    $this->assertStringContainsString('covered by *.dply-serverless.cloud', (string) $status);

    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('it creates a cloudflare a record when an explicit ip target is configured', function () {
    config(['serverless.testing_dns_target' => '203.0.113.10']);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-1', 'name' => 'dply-serverless.cloud']],
        ], 200),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => fn ($request) => $request->method() === 'POST'
            ? Http::response(['success' => true, 'result' => ['id' => 'rec-11', 'type' => 'A']], 200)
            : Http::response(['success' => true, 'result' => []], 200),
    ]);

    $site = Site::factory()->create(['name' => 'Laravel demo']);
    $site->ensureServerlessProxySlug();

    app(ServerlessFunctionDnsProvisioner::class)->provision($site->fresh());

    $dns = $site->fresh()->serverlessConfig()['dns'] ?? [];
    expect($dns['record_type'])->toBe('A');
    expect($dns['record_data'])->toBe('203.0.113.10');
});

test('it skips when no cloudflare token is configured for the apex', function () {
    config(['serverless.testing_dns.cloudflare_api_token' => null]);
    Http::fake();

    $site = Site::factory()->create(['name' => 'Laravel demo']);
    $site->ensureServerlessProxySlug();

    $status = app(ServerlessFunctionDnsProvisioner::class)->provision($site->fresh());

    $dns = $site->fresh()->serverlessConfig()['dns'] ?? [];
    expect($dns['status'])->toBe('skipped');
    expect($dns['reason'])->toBe('missing_token');
    $this->assertStringContainsString('Cloudflare', (string) $status);
    Http::assertNothingSent();
});

test('it skips when the apex is not a zone the cloudflare token can reach', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::response(['success' => true, 'result' => []], 200),
    ]);

    $site = Site::factory()->create(['name' => 'Laravel demo']);
    $site->ensureServerlessProxySlug();

    app(ServerlessFunctionDnsProvisioner::class)->provision($site->fresh());

    $dns = $site->fresh()->serverlessConfig()['dns'] ?? [];
    expect($dns['status'])->toBe('skipped');
    expect($dns['reason'])->toBe('unconfigured_zone');
});

test('it creates a cname to the zone apex when the record is missing', function () {
    useDigitalOceanZone();

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
    expect($dns['status'])->toBe('ready');
    expect($dns['hostname'])->toBe($slug.'.dply.host');
    expect($dns['record_type'])->toBe('CNAME');
    expect($dns['record_data'])->toBe('dply.host.');
    $this->assertStringContainsString('CNAME dply.host.', (string) $status);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/domains/dply.host/records'));
});

test('it does not create a duplicate when the record already exists', function () {
    useDigitalOceanZone();

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
    expect($dns['status'])->toBe('ready');
    expect($dns['record_id'])->toBe(7);

    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('it creates an a record when an explicit ip target is configured', function () {
    useDigitalOceanZone();
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
    expect($dns['record_type'])->toBe('A');
    expect($dns['record_data'])->toBe('203.0.113.10');
});

test('it skips when no app level token is configured', function () {
    useDigitalOceanZone();
    config(['services.digitalocean.token' => null]);
    Http::fake();

    $site = Site::factory()->create(['name' => 'Laravel demo']);
    $site->ensureServerlessProxySlug();

    $status = app(ServerlessFunctionDnsProvisioner::class)->provision($site->fresh());

    expect($site->fresh()->serverlessConfig()['dns']['status'] ?? null)->toBe('skipped');
    $this->assertStringContainsString('skipped', (string) $status);
    Http::assertNothingSent();
});
