<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\FunctionInvocation;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerlessTickCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $serverless
     */
    private function functionSite(string $status, array $serverless): Site
    {
        $server = Server::factory()->create([
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
                'digitalocean_functions' => [
                    'api_host' => 'https://faas.example',
                    'access_key' => 'id:secret',
                    'namespace' => 'fn-test',
                ],
            ],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'status' => $status,
            'meta' => ['serverless' => array_merge(['action_name' => 'laravel-demo'], $serverless)],
        ]);
    }

    private function fakeActivation(): void
    {
        Http::fake([
            'https://faas.example/api/v1/namespaces/_/actions/*' => Http::response([
                'activationId' => 'act-1',
                'duration' => 12,
                'annotations' => [],
                'logs' => [],
                'response' => [
                    'status' => 'success',
                    'success' => true,
                    'result' => ['statusCode' => 200, 'headers' => [], 'body' => 'ticked'],
                ],
            ], 200),
        ]);
    }

    public function test_it_ticks_enabled_active_functions_via_the_authenticated_api(): void
    {
        $this->fakeActivation();

        $site = $this->functionSite(Site::STATUS_FUNCTIONS_ACTIVE, ['background_enabled' => true]);

        $this->artisan('serverless:tick')->assertSuccessful();

        // One schedule + one queue tick, both recorded as source=tick rows.
        $this->assertSame(2, FunctionInvocation::query()->where('site_id', $site->id)->count());
        $this->assertSame(1, FunctionInvocation::query()->where('site_id', $site->id)->where('task', 'schedule')->count());
        $this->assertSame(1, FunctionInvocation::query()->where('site_id', $site->id)->where('task', 'queue')->count());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/actions/laravel-demo')
            && data_get($request->data(), '__ow_headers.x-dply-run') === 'schedule'
            && data_get($request->data(), '__ow_headers.x-dply-secret') === $site->fresh()->ensureServerlessCommandSecret());
    }

    public function test_it_skips_functions_without_background_enabled(): void
    {
        Http::fake();

        $this->functionSite(Site::STATUS_FUNCTIONS_ACTIVE, []);

        $this->artisan('serverless:tick')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, FunctionInvocation::query()->count());
    }

    public function test_it_skips_functions_that_are_not_yet_live(): void
    {
        Http::fake();

        $this->functionSite(Site::STATUS_FUNCTIONS_CONFIGURED, ['background_enabled' => true]);

        $this->artisan('serverless:tick')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_keep_warm_ticks_the_function_without_a_command_header(): void
    {
        $this->fakeActivation();

        $site = $this->functionSite(Site::STATUS_FUNCTIONS_ACTIVE, ['keep_warm' => true]);

        $this->artisan('serverless:tick')->assertSuccessful();

        $this->assertSame(1, FunctionInvocation::query()
            ->where('site_id', $site->id)->where('task', 'keep-warm')->count());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/actions/laravel-demo')
            && data_get($request->data(), '__ow_headers.x-dply-run') === null);
    }
}
