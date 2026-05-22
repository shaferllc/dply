<?php

declare(strict_types=1);

namespace Tests\Feature\FunctionLogIngestControllerTest;
use App\Models\FunctionInvocation;
use App\Models\Site;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const SECRET = 'ingest-secret-abc';
function functionSite(): Site
{
    return Site::factory()->create([
        'meta' => ['serverless' => ['log_ingest_secret' => SECRET]],
    ]);
}
/**
 * @param  array<string, mixed>  $payload
 */
function postRecord(Site $site, array $payload, ?string $secret = SECRET): \Illuminate\Testing\TestResponse
{
    $body = (string) json_encode($payload);
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($secret !== null) {
        $headers['HTTP_X_DPLY_SIGNATURE'] = hash_hmac('sha256', $body, $secret);
    }

    return $this->call('POST', route('hooks.functions.log', $site), [], [], [], $headers, $body);
}
test('a correctly signed payload records a web invocation', function () {
    $site = functionSite();

    postRecord($site, [
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
});
test('a 5xx status is recorded as a failed visit', function () {
    $site = functionSite();

    postRecord($site, ['method' => 'GET', 'path' => '/boom', 'status' => 500])
        ->assertStatus(202);

    $this->assertDatabaseHas('function_invocations', [
        'site_id' => $site->id,
        'source' => 'web',
        'status_code' => 500,
        'success' => false,
    ]);
});
test('it stores sanitized request context', function () {
    $site = functionSite();

    postRecord($site, [
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
    expect($row->context['ip'])->toBe('203.0.113.7');
    expect($row->context['country'])->toBe('US');
    expect($row->context['route'])->toBe('orders.index');
    expect($row->context['response_bytes'])->toBe(4096);
    $this->assertArrayNotHasKey('evil', $row->context);
});
test('a bad signature is rejected and records nothing', function () {
    $site = functionSite();

    postRecord($site, ['method' => 'GET', 'path' => '/', 'status' => 200], 'wrong-secret')
        ->assertStatus(401);

    $this->assertDatabaseCount('function_invocations', 0);
});
test('an unsigned request is rejected', function () {
    $site = functionSite();

    postRecord($site, ['method' => 'GET', 'path' => '/', 'status' => 200], null)
        ->assertStatus(401);

    $this->assertDatabaseCount('function_invocations', 0);
});
