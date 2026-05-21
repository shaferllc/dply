<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FunctionInvocation;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FunctionLogIngestControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ingest-secret-abc';

    private function functionSite(): Site
    {
        return Site::factory()->create([
            'meta' => ['serverless' => ['log_ingest_secret' => self::SECRET]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postRecord(Site $site, array $payload, ?string $secret = self::SECRET): \Illuminate\Testing\TestResponse
    {
        $body = (string) json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $headers['HTTP_X_DPLY_SIGNATURE'] = hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', route('hooks.functions.log', $site), [], [], [], $headers, $body);
    }

    public function test_a_correctly_signed_payload_records_a_web_invocation(): void
    {
        $site = $this->functionSite();

        $this->postRecord($site, [
            'method' => 'get',
            'path' => 'dashboard',
            'status' => 200,
            'duration_ms' => 73,
            'logs' => ['production.INFO: served the dashboard'],
        ])->assertStatus(202);

        $this->assertDatabaseHas('function_invocations', [
            'site_id' => $site->id,
            'source' => 'web',
            'method' => 'GET',
            'path' => '/dashboard',
            'status_code' => 200,
            'success' => true,
        ]);
    }

    public function test_a_5xx_status_is_recorded_as_a_failed_visit(): void
    {
        $site = $this->functionSite();

        $this->postRecord($site, ['method' => 'GET', 'path' => '/boom', 'status' => 500])
            ->assertStatus(202);

        $this->assertDatabaseHas('function_invocations', [
            'site_id' => $site->id,
            'source' => 'web',
            'status_code' => 500,
            'success' => false,
        ]);
    }

    public function test_it_stores_sanitized_request_context(): void
    {
        $site = $this->functionSite();

        $this->postRecord($site, [
            'method' => 'GET',
            'path' => '/orders',
            'status' => 200,
            'context' => [
                'ip' => '203.0.113.7',
                'country' => 'US',
                'route' => 'orders.index',
                'response_bytes' => 4096,
                'user_agent' => 'Mozilla/5.0',
                'evil' => 'unknown key — dropped',
            ],
        ])->assertStatus(202);

        $row = FunctionInvocation::query()->where('site_id', $site->id)->firstOrFail();
        $this->assertSame('203.0.113.7', $row->context['ip']);
        $this->assertSame('US', $row->context['country']);
        $this->assertSame('orders.index', $row->context['route']);
        $this->assertSame(4096, $row->context['response_bytes']);
        $this->assertArrayNotHasKey('evil', $row->context);
    }

    public function test_a_bad_signature_is_rejected_and_records_nothing(): void
    {
        $site = $this->functionSite();

        $this->postRecord($site, ['method' => 'GET', 'path' => '/', 'status' => 200], 'wrong-secret')
            ->assertStatus(401);

        $this->assertDatabaseCount('function_invocations', 0);
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $site = $this->functionSite();

        $this->postRecord($site, ['method' => 'GET', 'path' => '/', 'status' => 200], null)
            ->assertStatus(401);

        $this->assertDatabaseCount('function_invocations', 0);
    }
}
