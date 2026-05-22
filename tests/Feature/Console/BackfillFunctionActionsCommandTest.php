<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\FunctionAction;
use App\Models\FunctionInvocation;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillFunctionActionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function functionsSite(array $serverlessConfig): Site
    {
        $server = Server::factory()->create([
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'meta' => ['serverless' => $serverlessConfig],
        ]);
    }

    private function invocation(Site $site): FunctionInvocation
    {
        return FunctionInvocation::query()->create([
            'site_id' => $site->id,
            'source' => 'web',
            'method' => 'GET',
            'path' => '/',
            'status_code' => 200,
            'success' => true,
            'duration_ms' => 10,
            'cold' => false,
            'created_at' => now(),
        ]);
    }

    public function test_it_creates_one_code_action_per_serverless_site_from_meta(): void
    {
        $site = $this->functionsSite([
            'action_name' => 'orders-api',
            'runtime' => 'php:8.3',
            'entrypoint' => 'main',
            'action_url' => 'https://faas.example.com/api/v1/web/ns/default/orders-api',
            'limits' => ['memory' => 256, 'timeout' => 30000, 'concurrency' => 1],
        ]);

        $this->artisan('serverless:backfill-function-actions')->assertSuccessful();

        $action = FunctionAction::query()->where('site_id', $site->id)->sole();
        $this->assertSame('orders-api', $action->name);
        $this->assertSame(FunctionAction::KIND_CODE, $action->kind);
        $this->assertSame('php:8.3', $action->runtime);
        $this->assertSame('main', $action->entrypoint);
        $this->assertSame(256, $action->memory_mb);
        $this->assertSame(30000, $action->timeout_ms);
        $this->assertSame('https://faas.example.com/api/v1/web/ns/default/orders-api', $action->url);
    }

    public function test_it_links_existing_invocations_to_the_backfilled_action(): void
    {
        $site = $this->functionsSite(['action_name' => 'fn']);
        $a = $this->invocation($site);
        $b = $this->invocation($site);

        $this->artisan('serverless:backfill-function-actions')->assertSuccessful();

        $action = FunctionAction::query()->where('site_id', $site->id)->sole();
        $this->assertSame($action->id, $a->fresh()->function_action_id);
        $this->assertSame($action->id, $b->fresh()->function_action_id);
    }

    public function test_it_is_idempotent(): void
    {
        $site = $this->functionsSite(['action_name' => 'fn']);

        $this->artisan('serverless:backfill-function-actions')->assertSuccessful();
        $this->artisan('serverless:backfill-function-actions')->assertSuccessful();

        $this->assertSame(1, FunctionAction::query()->where('site_id', $site->id)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $site = $this->functionsSite(['action_name' => 'fn']);
        $this->invocation($site);

        $this->artisan('serverless:backfill-function-actions', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(0, FunctionAction::query()->count());
        $this->assertNull(FunctionInvocation::query()->first()->function_action_id);
    }

    public function test_it_ignores_non_serverless_sites(): void
    {
        Site::factory()->create();

        $this->artisan('serverless:backfill-function-actions')->assertSuccessful();

        $this->assertSame(0, FunctionAction::query()->count());
    }

    public function test_it_falls_back_to_the_site_slug_when_no_action_name_is_known(): void
    {
        $site = $this->functionsSite([]);
        $site->update(['slug' => 'fallback-fn']);

        $this->artisan('serverless:backfill-function-actions')->assertSuccessful();

        $this->assertSame('fallback-fn', FunctionAction::query()->where('site_id', $site->id)->sole()->name);
    }
}
