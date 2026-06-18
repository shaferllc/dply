<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\RateLimitRetryTest;

use App\Models\ProviderCredential;
use App\Modules\Imports\Services\Forge\ForgeClient;
use App\Modules\Imports\Services\Ploi\PloiClient;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sleep::fake();
});
test('ploi client retries on 429 and succeeds', function () {
    Http::fake([
        'https://ploi.io/api/servers' => Http::sequence()
            ->push(['message' => 'Too Many Requests'], 429)
            ->push(['data' => [['id' => 1, 'name' => 'a']]], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_test'],
    ]);
    $client = new PloiClient($credential);

    $response = $client->get('/servers');
    expect($response->successful())->toBeTrue();
    expect($response->json('data'))->toBe([['id' => 1, 'name' => 'a']]);
    Http::assertSentCount(2);
});
test('ploi client retries on 503', function () {
    Http::fake([
        'https://ploi.io/api/servers' => Http::sequence()
            ->push('upstream busy', 503)
            ->push(['data' => []], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_test'],
    ]);

    $response = (new PloiClient($credential))->get('/servers');
    expect($response->successful())->toBeTrue();
});
test('ploi client does not retry 401', function () {
    Http::fake([
        'https://ploi.io/api/servers' => Http::response('Unauthorized', 401),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_test'],
    ]);

    $response = (new PloiClient($credential))->get('/servers');
    expect($response->status())->toBe(401);
    Http::assertSentCount(1);
});
test('ploi client returns 429 after max attempts', function () {
    Http::fake([
        'https://ploi.io/api/servers' => Http::response(['message' => 'Too Many Requests'], 429),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_test'],
    ]);

    $response = (new PloiClient($credential))->get('/servers');
    expect($response->status())->toBe(429);
    Http::assertSentCount(3);
});
test('forge client retries on 429', function () {
    Http::fake([
        'https://forge.laravel.com/api/v1/servers' => Http::sequence()
            ->push('Rate limit', 429)
            ->push(['servers' => []], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'forge',
        'credentials' => ['api_token' => 'forge_test'],
    ]);

    $response = (new ForgeClient($credential))->get('/servers');
    expect($response->successful())->toBeTrue();
    Http::assertSentCount(2);
});
test('ploi client honors retry after header when present', function () {
    Http::fake([
        'https://ploi.io/api/servers' => Http::sequence()
            ->push(['message' => 'Too Many Requests'], 429, ['Retry-After' => '3'])
            ->push(['data' => []], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_test'],
    ]);

    $response = (new PloiClient($credential))->get('/servers');
    expect($response->successful())->toBeTrue();

    // Retry-After was 3s; the request layer should have slept at least that long.
    // Sleep::fake() lets us assert on the slept duration without actually waiting.
    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(function (CarbonInterval $interval): bool {
        return $interval->totalMilliseconds >= 3000;
    });
});
