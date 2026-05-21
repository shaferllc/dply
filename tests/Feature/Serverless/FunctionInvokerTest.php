<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless;

use App\Models\FunctionInvocation;
use App\Models\Server;
use App\Models\Site;
use App\Services\Serverless\FunctionInvoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FunctionInvokerTest extends TestCase
{
    use RefreshDatabase;

    private function functionSite(bool $provisioned = true): Site
    {
        $functionsMeta = $provisioned
            ? ['api_host' => 'https://faas.example', 'access_key' => 'id:secret', 'namespace' => 'fn-test']
            : [];

        $server = Server::factory()->create([
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
                'digitalocean_functions' => $functionsMeta,
            ],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'meta' => ['serverless' => ['action_name' => 'laravel-demo']],
        ]);
    }

    public function test_it_records_a_successful_activation_with_logs_and_cold_start(): void
    {
        Http::fake([
            'https://faas.example/api/v1/namespaces/_/actions/*' => Http::response([
                'activationId' => 'act-99',
                'duration' => 128,
                'annotations' => [['key' => 'initTime', 'value' => 240]],
                'logs' => ['production.INFO: warmed up'],
                'response' => [
                    'status' => 'success',
                    'success' => true,
                    'result' => ['statusCode' => 201, 'headers' => [], 'body' => 'created'],
                ],
            ], 200),
        ]);

        $site = $this->functionSite();

        $result = app(FunctionInvoker::class)->invoke($site, FunctionInvocation::SOURCE_TEST, null, [
            '__ow_method' => 'GET',
            '__ow_path' => '',
        ]);

        $this->assertTrue($result['ok']);
        $invocation = $result['invocation'];
        $this->assertNotNull($invocation);
        $this->assertSame('test', $invocation->source);
        $this->assertSame('act-99', $invocation->activation_id);
        $this->assertSame(201, $invocation->status_code);
        $this->assertSame(128, $invocation->duration_ms);
        $this->assertTrue($invocation->cold);
        $this->assertSame(['production.INFO: warmed up'], $invocation->logLines());
    }

    public function test_an_unprovisioned_host_fails_without_recording_a_row(): void
    {
        $site = $this->functionSite(provisioned: false);

        $result = app(FunctionInvoker::class)->invoke($site, FunctionInvocation::SOURCE_TICK, 'schedule', []);

        $this->assertFalse($result['ok']);
        $this->assertNull($result['invocation']);
        $this->assertDatabaseCount('function_invocations', 0);
    }

    public function test_a_transport_failure_still_records_a_failed_row(): void
    {
        Http::fake([
            'https://faas.example/*' => fn () => throw new \RuntimeException('connection timed out'),
        ]);

        $site = $this->functionSite();

        $result = app(FunctionInvoker::class)->invoke($site, FunctionInvocation::SOURCE_TICK, 'queue', []);

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['invocation']);
        $this->assertFalse($result['invocation']->success);
        $this->assertDatabaseHas('function_invocations', [
            'site_id' => $site->id,
            'source' => 'tick',
            'task' => 'queue',
            'success' => false,
            'activation_id' => null,
        ]);
    }
}
