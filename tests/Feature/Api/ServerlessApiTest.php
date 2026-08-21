<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\AppLogRecord;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Models\FunctionInvocation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('serverless sites api lists org functions only', function () {
    [$headers, $site] = serverlessApiContext(['serverless.read']);

    $otherOrg = Organization::factory()->create();
    Site::factory()->create([
        'organization_id' => $otherOrg->id,
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ]);

    $this->getJson('/api/v1/serverless/sites', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $site->id)
        ->assertJsonPath('data.0.runtime_profile', 'digitalocean_functions_web');
});

test('serverless sites api does not expose vm sites', function () {
    [$headers] = serverlessApiContext(['serverless.read']);

    $this->getJson('/api/v1/serverless/sites', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $vmSite = Site::factory()->create(['meta' => ['runtime_profile' => 'php_web']]);

    $this->getJson('/api/v1/serverless/sites/'.$vmSite->id, $headers)
        ->assertNotFound();
});

test('serverless site show reports settled invocation health', function () {
    [$headers, $site] = serverlessApiContext(['serverless.read']);

    invocation($site, ['success' => true, 'duration_ms' => 100]);
    invocation($site, ['success' => false, 'duration_ms' => 300, 'cold' => true]);
    // Pending rows carry a zero duration and no outcome — they must not drag
    // the average down or count as a success.
    invocation($site, ['success' => true, 'duration_ms' => 0, 'state' => FunctionInvocation::STATE_PENDING]);

    $this->getJson('/api/v1/serverless/sites/'.$site->id, $headers)
        ->assertOk()
        ->assertJsonPath('data.health.invocations', 2)
        ->assertJsonPath('data.health.failed', 1)
        ->assertJsonPath('data.health.error_rate', 0.5)
        ->assertJsonPath('data.health.cold_starts', 1)
        ->assertJsonPath('data.health.avg_duration_ms', 200);
});

test('serverless invocations api returns newest first and filters failures', function () {
    [$headers, $site] = serverlessApiContext(['serverless.read']);

    invocation($site, ['success' => true, 'created_at' => now()->subMinutes(5)]);
    $failed = invocation($site, ['success' => false, 'status_code' => 500, 'created_at' => now()->subMinute()]);

    $this->getJson('/api/v1/serverless/sites/'.$site->id.'/invocations', $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', (string) $failed->id);

    $this->getJson('/api/v1/serverless/sites/'.$site->id.'/invocations?failed=1', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $failed->id)
        ->assertJsonPath('data.0.status_code', 500);
});

test('serverless invocations api excludes pending rows from the failure feed', function () {
    [$headers, $site] = serverlessApiContext(['serverless.read']);

    invocation($site, ['success' => false, 'state' => FunctionInvocation::STATE_PENDING]);

    $this->getJson('/api/v1/serverless/sites/'.$site->id.'/invocations?failed=1', $headers)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('serverless invocations api tails ascending from a since cursor', function () {
    [$headers, $site] = serverlessApiContext(['serverless.read']);

    invocation($site, ['created_at' => now()->subMinutes(10)]);
    $mid = invocation($site, ['created_at' => now()->subMinutes(5)]);
    $newest = invocation($site, ['created_at' => now()->subMinute()]);

    $response = $this->getJson(
        '/api/v1/serverless/sites/'.$site->id.'/invocations?since='.urlencode(now()->subMinutes(7)->toIso8601String()),
        $headers,
    )->assertOk();

    $response->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', (string) $mid->id)
        ->assertJsonPath('data.1.id', (string) $newest->id)
        ->assertJsonPath('meta.tail_cursor', $newest->created_at->toIso8601String());
});

test('serverless invocations api keeps the caller cursor when a poll is empty', function () {
    [$headers, $site] = serverlessApiContext(['serverless.read']);

    $cursor = now()->subMinute()->toIso8601String();

    $this->getJson('/api/v1/serverless/sites/'.$site->id.'/invocations?since='.urlencode($cursor), $headers)
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.tail_cursor', $cursor);
});

test('serverless invocation show strips the whisk sentinel from log lines', function () {
    [$headers, $site] = serverlessApiContext(['serverless.read']);

    $row = invocation($site, [
        'result_excerpt' => 'boom',
        'log_lines' => [
            '2026-08-21T02:05:16.805709862Z stdout: real output',
            '2026-08-21T02:05:16.805709862Z stderr:',
            '2026-08-21T02:05:16.805948427Z stdout:',
        ],
    ]);

    $this->getJson('/api/v1/serverless/sites/'.$site->id.'/invocations/'.$row->id, $headers)
        ->assertOk()
        ->assertJsonPath('data.result_excerpt', 'boom')
        ->assertJsonCount(1, 'data.log_lines')
        ->assertJsonPath('data.log_lines.0', '2026-08-21T02:05:16.805709862Z stdout: real output');
});

test('serverless invocation show 404s across sites', function () {
    [$headers, $site] = serverlessApiContext(['serverless.read']);
    [, $otherSite] = serverlessApiContext(['serverless.read']);

    $row = invocation($otherSite, []);

    $this->getJson('/api/v1/serverless/sites/'.$site->id.'/invocations/'.$row->id, $headers)
        ->assertNotFound();
});

test('serverless logs api filters by level and tails on created_at', function () {
    [$headers, $site] = serverlessApiContext(['serverless.read']);

    AppLogRecord::create([
        'site_id' => $site->id,
        'level' => 'info',
        'message' => 'hello',
        'created_at' => now()->subSeconds(30),
    ]);
    $error = AppLogRecord::create([
        'site_id' => $site->id,
        'level' => 'error',
        'message' => 'boom',
        'created_at' => now()->subSeconds(10),
    ]);

    $this->getJson('/api/v1/serverless/sites/'.$site->id.'/logs', $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/serverless/sites/'.$site->id.'/logs?level=error', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.message', 'boom')
        ->assertJsonPath('meta.tail_cursor', $error->created_at->toIso8601String());
});

test('serverless api requires the serverless read ability', function () {
    [$headers, $site] = serverlessApiContext(['sites.read']);

    $this->getJson('/api/v1/serverless/sites', $headers)->assertForbidden();
    $this->getJson('/api/v1/serverless/sites/'.$site->id.'/invocations', $headers)->assertForbidden();
});

/**
 * @param  list<string>  $abilities
 * @return array{0: array<string, string>, 1: Site}
 */
function serverlessApiContext(array $abilities): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    ['plaintext' => $plain] = ApiToken::createToken($user, $org, 'serverless-api-test', null, $abilities);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => [
                'runtime' => 'php:8.4',
                'namespace' => 'fn-abc123',
            ],
        ],
    ]);

    return [
        [
            'Authorization' => 'Bearer '.$plain,
            'Accept' => 'application/json',
        ],
        $site,
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function invocation(Site $site, array $attributes): FunctionInvocation
{
    return FunctionInvocation::create(array_merge([
        'site_id' => $site->id,
        'source' => FunctionInvocation::SOURCE_WEB,
        'state' => FunctionInvocation::STATE_COMPLETED,
        'success' => true,
        'duration_ms' => 10,
        'cold' => false,
        'created_at' => now(),
    ], $attributes));
}
