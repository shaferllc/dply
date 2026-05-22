<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless;

use App\Models\FunctionAction;
use App\Models\Server;
use App\Models\Site;
use App\Services\Serverless\ServerlessTriggerProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerlessTriggerProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function action(array $trigger): FunctionAction
    {
        $server = Server::factory()->create([
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
                'digitalocean_functions' => [
                    'api_host' => 'https://faas-nyc1.example.com',
                    'access_key' => 'keyid:keysecret',
                ],
            ],
        ]);
        $site = Site::factory()->create(['server_id' => $server->id]);

        return FunctionAction::query()->create([
            'site_id' => $site->id,
            'name' => 'orders-api',
            'kind' => FunctionAction::KIND_CODE,
            'trigger' => $trigger,
        ]);
    }

    public function test_it_provisions_a_trigger_feed_binding_and_rule(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $action = $this->action(['cron' => '*/5 * * * *', 'enabled' => true]);

        $result = (new ServerlessTriggerProvisioner)->provision($action);

        $this->assertTrue($result['ok']);
        $this->assertSame('orders-api-dply-cron', $result['trigger']);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/triggers/orders-api-dply-cron'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/actions/alarms/alarm')
            && $request['lifecycleEvent'] === 'CREATE'
            && $request['cron'] === '*/5 * * * *');
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/rules/orders-api-dply-cron-rule')
            && $request['action'] === '/_/orders-api');
    }

    public function test_an_action_with_no_enabled_schedule_provisions_nothing(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $action = $this->action(['cron' => '*/5 * * * *', 'enabled' => false]);

        $result = (new ServerlessTriggerProvisioner)->provision($action);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['trigger']);
        // Only teardown DELETEs may go out — never a trigger PUT.
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/triggers/'));
    }

    public function test_remove_tears_down_the_rule_feed_and_trigger(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $action = $this->action(['cron' => '0 * * * *', 'enabled' => true]);

        $result = (new ServerlessTriggerProvisioner)->remove($action);

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/rules/orders-api-dply-cron-rule'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/actions/alarms/alarm')
            && $request['lifecycleEvent'] === 'DELETE');
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/triggers/orders-api-dply-cron'));
    }
}
