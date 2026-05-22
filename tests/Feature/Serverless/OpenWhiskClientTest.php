<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless;

use App\Models\Server;
use App\Services\Serverless\OpenWhiskClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenWhiskClientTest extends TestCase
{
    use RefreshDatabase;

    private function functionServer(bool $provisioned = true): Server
    {
        return Server::factory()->create([
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
                'digitalocean_functions' => $provisioned
                    ? ['api_host' => 'https://faas.example', 'access_key' => 'id:secret', 'namespace' => 'fn-test']
                    : [],
            ],
        ]);
    }

    public function test_an_unprovisioned_host_makes_no_call_and_returns_an_error(): void
    {
        Http::fake();

        $result = (new OpenWhiskClient($this->functionServer(provisioned: false)))->actions();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not provisioned', (string) $result['error']);
        Http::assertNothingSent();
    }

    public function test_it_lists_actions(): void
    {
        Http::fake([
            'https://faas.example/api/v1/namespaces/_/actions*' => Http::response([
                ['name' => 'laravel-demo', 'version' => '0.0.11'],
            ], 200),
        ]);

        $result = (new OpenWhiskClient($this->functionServer()))->actions();

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['data']);
    }

    public function test_it_creates_a_trigger_with_parameters(): void
    {
        Http::fake(['https://faas.example/*' => Http::response(['name' => 'nightly'], 200)]);

        $result = (new OpenWhiskClient($this->functionServer()))->putTrigger('nightly', ['region' => 'nyc']);

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/triggers/nightly')
            && str_contains($request->url(), 'overwrite=true')
            && data_get($request->data(), 'parameters.0.key') === 'region');
    }

    public function test_it_creates_a_rule_binding_a_trigger_to_an_action(): void
    {
        Http::fake(['https://faas.example/*' => Http::response(['name' => 'r'], 200)]);

        $result = (new OpenWhiskClient($this->functionServer()))->putRule('r', 'nightly', 'laravel-demo');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/rules/r')
            && $request['trigger'] === 'nightly'
            && $request['action'] === 'laravel-demo');
    }

    public function test_it_deletes_an_action(): void
    {
        Http::fake(['https://faas.example/*' => Http::response([], 200)]);

        $result = (new OpenWhiskClient($this->functionServer()))->deleteAction('laravel-demo');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/actions/laravel-demo'));
    }

    public function test_an_http_error_surfaces_as_a_failed_result(): void
    {
        Http::fake(['https://faas.example/*' => Http::response(['error' => 'not found'], 404)]);

        $result = (new OpenWhiskClient($this->functionServer()))->action('missing');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HTTP 404', (string) $result['error']);
    }
}
