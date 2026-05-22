<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless;

use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Services\Serverless\FunctionScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FunctionScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function functionSite(bool $provisioned = true): Site
    {
        $credential = ProviderCredential::factory()->create([
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'tok-123'],
        ]);

        $server = Server::factory()->create([
            'provider_credential_id' => $provisioned ? $credential->id : null,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
                'digitalocean_functions' => $provisioned
                    ? ['namespace' => 'fn-test', 'api_host' => 'https://faas.example', 'access_key' => 'id:secret']
                    : [],
            ],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'meta' => ['serverless' => ['action_name' => 'laravel-demo']],
        ]);
    }

    public function test_it_lists_scheduled_triggers(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/functions/namespaces/*/triggers' => Http::response([
                'triggers' => [['name' => 'dply-hourly', 'scheduled_details' => ['cron' => '0 * * * *']]],
            ], 200),
        ]);

        $result = app(FunctionScheduleService::class)->list($this->functionSite());

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['triggers']);
    }

    public function test_it_creates_a_scheduled_trigger_bound_to_the_function(): void
    {
        Http::fake(['api.digitalocean.com/*' => Http::response(['trigger' => ['name' => 'dply-hourly']], 200)]);

        $result = app(FunctionScheduleService::class)->add($this->functionSite(), 'dply-hourly', '0 * * * *');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/functions/namespaces/fn-test/triggers')
            && $request['type'] === 'SCHEDULED'
            && $request['function'] === 'laravel-demo'
            && data_get($request->data(), 'scheduled_details.cron') === '0 * * * *');
    }

    public function test_it_removes_a_scheduled_trigger(): void
    {
        Http::fake(['api.digitalocean.com/*' => Http::response(null, 204)]);

        $result = app(FunctionScheduleService::class)->remove($this->functionSite(), 'dply-hourly');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/triggers/dply-hourly'));
    }

    public function test_an_unprovisioned_host_returns_an_error(): void
    {
        Http::fake();

        $result = app(FunctionScheduleService::class)->list($this->functionSite(provisioned: false));

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['triggers']);
        Http::assertNothingSent();
    }

    public function test_preset_and_custom_trigger_names_are_stable(): void
    {
        $service = new FunctionScheduleService;

        $this->assertSame('dply-hourly', $service->presetTriggerName('hourly'));
        $this->assertSame(
            $service->customTriggerName('0 9 * * 1-5'),
            $service->customTriggerName('0 9 * * 1-5'),
        );
    }
}
