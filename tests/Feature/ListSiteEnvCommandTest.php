<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ListSiteEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_human_listing_masks_values_by_default(): void
    {
        $site = $this->makeSite(['env_file_content' => 'API_KEY=super-secret-12345']);

        $exit = Artisan::call('dply:site:env-list', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('API_KEY', $output);
        $this->assertStringNotContainsString('super-secret-12345', $output);
        $this->assertStringContainsString('•', $output);
    }

    public function test_reveal_flag_prints_cleartext(): void
    {
        $site = $this->makeSite(['env_file_content' => 'API_KEY=super-secret-12345']);

        Artisan::call('dply:site:env-list', [
            'site' => $site->slug,
            '--reveal' => true,
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString('super-secret-12345', $output);
    }

    public function test_json_output_returns_structured_payload(): void
    {
        $site = $this->makeSite(['env_file_content' => "B_KEY=b-val\nA_KEY=a-val"]);

        Artisan::call('dply:site:env-list', [
            'site' => $site->slug,
            '--reveal' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(2, $decoded['count']);
        $this->assertTrue($decoded['revealed']);
        // Sorted by key for determinism.
        $this->assertSame('A_KEY', $decoded['variables'][0]['key']);
        $this->assertSame('a-val', $decoded['variables'][0]['value']);
        $this->assertSame('B_KEY', $decoded['variables'][1]['key']);
    }

    public function test_empty_listing_emits_friendly_message(): void
    {
        $site = $this->makeSite();

        Artisan::call('dply:site:env-list', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertStringContainsString('No environment variables', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:env-list', ['site' => 'nope']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeSite(array $attrs = []): Site
    {
        $server = Server::factory()->create();

        return Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'slug' => 'jobs',
        ], $attrs));
    }
}
