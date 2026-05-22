<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports;

use App\Models\ProviderCredential;
use App\Services\Imports\Forge\ForgeClient;
use App\Services\Imports\Ploi\PloiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Both clients now retry on 429 / 5xx with exponential backoff + Retry-After
 * support. Tests use Sleep::fake() so the test suite doesn't actually wait
 * the backoff window between attempts.
 */
class RateLimitRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_ploi_client_retries_on_429_and_succeeds(): void
    {
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
        $this->assertTrue($response->successful());
        $this->assertSame([['id' => 1, 'name' => 'a']], $response->json('data'));
        Http::assertSentCount(2);
    }

    public function test_ploi_client_retries_on_503(): void
    {
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
        $this->assertTrue($response->successful());
    }

    public function test_ploi_client_does_not_retry_401(): void
    {
        Http::fake([
            'https://ploi.io/api/servers' => Http::response('Unauthorized', 401),
        ]);

        $credential = ProviderCredential::factory()->create([
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_test'],
        ]);

        $response = (new PloiClient($credential))->get('/servers');
        $this->assertSame(401, $response->status());
        Http::assertSentCount(1);
    }

    public function test_ploi_client_returns_429_after_max_attempts(): void
    {
        Http::fake([
            'https://ploi.io/api/servers' => Http::response(['message' => 'Too Many Requests'], 429),
        ]);

        $credential = ProviderCredential::factory()->create([
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_test'],
        ]);

        $response = (new PloiClient($credential))->get('/servers');
        $this->assertSame(429, $response->status());
        Http::assertSentCount(3);
    }

    public function test_forge_client_retries_on_429(): void
    {
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
        $this->assertTrue($response->successful());
        Http::assertSentCount(2);
    }

    public function test_ploi_client_honors_retry_after_header_when_present(): void
    {
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
        $this->assertTrue($response->successful());

        // Retry-After was 3s; the request layer should have slept at least that long.
        // Sleep::fake() lets us assert on the slept duration without actually waiting.
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(function (\Carbon\CarbonInterval $interval): bool {
            return $interval->totalMilliseconds >= 3000;
        });
    }
}
