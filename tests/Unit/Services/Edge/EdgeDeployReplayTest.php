<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge;

use App\Models\EdgeAccessLog;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Edge\EdgeDeployReplayRunner;
use App\Services\Edge\EdgeDeployReplaySampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('replay sampler dedupes method and path from access logs', function (): void {
    $org = Organization::factory()->create();
    $site = Site::factory()->create(['organization_id' => $org->id]);

    EdgeAccessLog::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'hostname' => 'app.example.com',
        'method' => 'GET',
        'path' => '/api/health',
        'status_code' => 200,
        'duration_ms' => 40,
        'occurred_at' => now()->subMinutes(5),
    ]);
    EdgeAccessLog::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'hostname' => 'app.example.com',
        'method' => 'GET',
        'path' => '/api/health',
        'status_code' => 200,
        'duration_ms' => 55,
        'occurred_at' => now()->subMinutes(4),
    ]);
    EdgeAccessLog::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'hostname' => 'app.example.com',
        'method' => 'GET',
        'path' => '/pricing',
        'status_code' => 200,
        'duration_ms' => 80,
        'occurred_at' => now()->subMinutes(3),
    ]);

    $samples = app(EdgeDeployReplaySampler::class)->sample($site, 10, 60);

    expect($samples)->toHaveCount(2)
        ->and(collect($samples)->pluck('path')->all())->toContain('/api/health', '/pricing');
});

test('replay runner compares preview responses to production samples', function (): void {
    Http::fake([
        'https://preview.example.com/*' => Http::response('ok', 200),
    ]);

    $previewSite = Site::factory()->create([
        'meta' => ['edge' => ['live_url' => 'https://preview.example.com']],
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $previewSite->id,
        'organization_id' => $previewSite->organization_id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/preview',
        'published_at' => now(),
    ]);

    $payload = app(EdgeDeployReplayRunner::class)->run($previewSite, [
        ['method' => 'GET', 'path' => '/health', 'prod_status' => 200, 'prod_duration_ms' => 30],
    ]);

    expect($payload['summary']['total'])->toBe(1)
        ->and($payload['summary']['status_match'])->toBe(1)
        ->and($payload['results'][0]['preview_status'])->toBe(200);
});
