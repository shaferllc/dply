<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

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
        return Site::factory()->create([
            'status' => $status,
            'meta' => ['serverless' => $serverless],
        ]);
    }

    public function test_it_ticks_enabled_active_functions(): void
    {
        Http::fake();

        $site = $this->functionSite(Site::STATUS_FUNCTIONS_ACTIVE, [
            'background_enabled' => true,
            'action_url' => 'https://faas.example/api/v1/web/ns/default/fn',
        ]);

        $this->artisan('serverless:tick')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === $site->meta['serverless']['action_url']
            && $request->hasHeader('X-Dply-Run', 'schedule')
            && $request->hasHeader('X-Dply-Secret', (string) $site->webhook_secret));
        Http::assertSent(fn ($request) => $request->hasHeader('X-Dply-Run', 'queue'));
    }

    public function test_it_skips_functions_without_background_enabled(): void
    {
        Http::fake();

        $this->functionSite(Site::STATUS_FUNCTIONS_ACTIVE, [
            'action_url' => 'https://faas.example/api/v1/web/ns/default/fn',
        ]);

        $this->artisan('serverless:tick')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_skips_functions_that_are_not_yet_live(): void
    {
        Http::fake();

        $this->functionSite(Site::STATUS_FUNCTIONS_CONFIGURED, [
            'background_enabled' => true,
            'action_url' => 'https://faas.example/api/v1/web/ns/default/fn',
        ]);

        $this->artisan('serverless:tick')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_keep_warm_pings_the_function_without_a_command_header(): void
    {
        Http::fake();

        $site = $this->functionSite(Site::STATUS_FUNCTIONS_ACTIVE, [
            'keep_warm' => true,
            'action_url' => 'https://faas.example/api/v1/web/ns/default/fn',
        ]);

        $this->artisan('serverless:tick')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === $site->meta['serverless']['action_url']
            && ! $request->hasHeader('X-Dply-Run'));
    }
}
