<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Serverless\ServerlessFunctionDnsProvisionerTest;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessFunctionDnsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.digitalocean.testing_domains' => ['dply.host'],
        'services.digitalocean.token' => 'dop_v1_test',
        'services.digitalocean.serverless_function_dns_target' => null,
    ]);
});
test('it creates a cname to the zone apex when the record is missing', function () {
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
    config(['services.digitalocean.token' => null]);
    Http::fake();

    $site = Site::factory()->create(['name' => 'Laravel demo']);
    $site->ensureServerlessProxySlug();

    $status = app(ServerlessFunctionDnsProvisioner::class)->provision($site->fresh());

    expect($site->fresh()->serverlessConfig()['dns']['status'] ?? null)->toBe('skipped');
    $this->assertStringContainsString('skipped', (string) $status);
    Http::assertNothingSent();
});
