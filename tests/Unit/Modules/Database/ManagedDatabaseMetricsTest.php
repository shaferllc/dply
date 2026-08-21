<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Database\ManagedDatabaseMetricsTest;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Database\Services\ManagedDatabaseMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function metricsDatabase(array $overrides = []): CloudDatabase
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 'tok'],
    ]);

    return CloudDatabase::factory()->active()->create(array_merge([
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'backend_id' => 'do-metrics-1',
    ], $overrides));
}

/** A Prometheus matrix with $count samples ending now, one per minute. */
function matrix(array $values): array
{
    $now = now()->getTimestamp();
    $pairs = [];
    foreach (array_values($values) as $i => $value) {
        $pairs[] = [$now - ((count($values) - $i) * 60), (string) $value];
    }

    return ['data' => ['result' => [['metric' => [], 'values' => $pairs]]]];
}

beforeEach(function () {
    Cache::flush();
});

test('buckets provider samples into min avg max series', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/monitoring/metrics/database/*' => Http::response(matrix([10, 20, 30, 40])),
    ]);

    $charts = app(ManagedDatabaseMetrics::class)->forWindow(metricsDatabase(), '1h');

    expect($charts)->not->toBeEmpty();

    $cpu = collect($charts)->firstWhere('key', 'cpu');
    expect($cpu)->not->toBeNull()
        ->and($cpu['format'])->toBe('percent')
        ->and($cpu['series'])->not->toBeEmpty();

    $values = collect($cpu['series'])->flatMap(fn (array $p): array => [$p['min'], $p['avg'], $p['max']]);
    expect($values->min())->toBe(10.0)
        ->and($values->max())->toBe(40.0)
        ->and($cpu['latest'])->not->toBeNull();
});

test('a failing metric endpoint costs one chart, not the page', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/monitoring/metrics/database/*' => Http::response(['message' => 'boom'], 500),
    ]);

    $charts = app(ManagedDatabaseMetrics::class)->forWindow(metricsDatabase(), '24h');

    expect($charts)->not->toBeEmpty();
    foreach ($charts as $chart) {
        expect($chart['series'])->toBe([])
            ->and($chart['latest'])->toBeNull();
    }
});

test('valkey clusters are not offered a disk chart', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/monitoring/metrics/database/*' => Http::response(matrix([5])),
    ]);

    $charts = app(ManagedDatabaseMetrics::class)->forWindow(
        metricsDatabase(['engine' => CloudDatabase::ENGINE_REDIS]),
        '1h',
    );

    expect(collect($charts)->pluck('key')->all())->not->toContain('disk_utilization');
});

test('an external database reports no metrics', function () {
    $database = metricsDatabase(['backend' => CloudDatabase::BACKEND_EXTERNAL]);

    expect(app(ManagedDatabaseMetrics::class)->supports($database))->toBeFalse()
        ->and(app(ManagedDatabaseMetrics::class)->forWindow($database, '1h'))->toBe([]);
});

test('a database with no cluster id yet reports no metrics', function () {
    $database = metricsDatabase(['backend_id' => null]);

    expect(app(ManagedDatabaseMetrics::class)->supports($database))->toBeFalse();
});
