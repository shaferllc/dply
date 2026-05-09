<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FindFleetEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_a_key_across_multiple_sites(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create([
            'server_id' => $server->id,
            'name' => 'alpha-site',
            'slug' => 'alpha',
            'env_file_content' => 'DATABASE_URL=postgres://a',
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'name' => 'bravo-site',
            'slug' => 'bravo',
            'env_file_content' => "DATABASE_URL=postgres://b\nOTHER_KEY=irrelevant",
        ]);

        Artisan::call('dply:fleet:env-find', [
            'key' => 'DATABASE_URL',
            '--reveal' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(2, $decoded['count']);
        $this->assertSame('alpha-site', $decoded['matches'][0]['site_name']);
        $this->assertSame('postgres://a', $decoded['matches'][0]['value']);
        $this->assertSame('bravo-site', $decoded['matches'][1]['site_name']);
    }

    public function test_prefix_mode_matches_keys_with_same_prefix(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'alpha',
            'env_file_content' => "AWS_REGION=us-east-1\nAWS_BUCKET=mybucket\nOTHER=x",
        ]);

        Artisan::call('dply:fleet:env-find', [
            'key' => 'AWS_',
            '--prefix' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(2, $decoded['count']);
        $keys = array_column($decoded['matches'], 'key');
        sort($keys);
        $this->assertSame(['AWS_BUCKET', 'AWS_REGION'], $keys);
    }

    public function test_masks_values_by_default(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'alpha',
            'env_file_content' => 'API_KEY=super-secret-value',
        ]);

        Artisan::call('dply:fleet:env-find', [
            'key' => 'API_KEY',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertStringNotContainsString('super-secret-value', json_encode($decoded));
        $this->assertStringContainsString('•', $decoded['matches'][0]['value']);
    }

    public function test_exits_non_zero_on_no_matches(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id, 'slug' => 'alpha']);

        $exit = Artisan::call('dply:fleet:env-find', [
            'key' => 'NONEXISTENT',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame([], $decoded['matches']);
    }

    public function test_rejects_empty_key(): void
    {
        $exit = Artisan::call('dply:fleet:env-find', ['key' => '']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cannot be empty', $output);
    }
}
