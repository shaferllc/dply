<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FindFleetDomainCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_exact_match_with_site_and_server_context(): void
    {
        $server = Server::factory()->create(['name' => 'web-1', 'ip_address' => '203.0.113.10']);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'name' => 'jobs-app',
            'runtime' => 'node',
        ]);
        $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

        Artisan::call('dply:fleet:domain-find', [
            'hostname' => 'jobs.example.com',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['count']);
        $m = $decoded['matches'][0];
        $this->assertSame('jobs.example.com', $m['hostname']);
        $this->assertTrue($m['is_primary']);
        $this->assertSame('jobs-app', $m['site_name']);
        $this->assertSame('node', $m['site_runtime']);
        $this->assertSame('web-1', $m['server_name']);
        $this->assertSame('203.0.113.10', $m['server_ip']);
    }

    public function test_normalizes_input_hostname(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $site->domains()->create(['hostname' => 'jobs.example.com']);

        Artisan::call('dply:fleet:domain-find', [
            'hostname' => 'HTTPS://Jobs.Example.com/',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['count']);
    }

    public function test_contains_mode_finds_substring_matches(): void
    {
        $server = Server::factory()->create();
        $a = Site::factory()->create(['server_id' => $server->id, 'slug' => 'alpha']);
        $b = Site::factory()->create(['server_id' => $server->id, 'slug' => 'bravo']);
        $a->domains()->create(['hostname' => 'jobs.example.com']);
        $b->domains()->create(['hostname' => 'careers.example.com']);
        $b->domains()->create(['hostname' => 'unrelated.test.io']);

        Artisan::call('dply:fleet:domain-find', [
            'hostname' => 'example.com',
            '--contains' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(2, $decoded['count']);
        $hosts = array_column($decoded['matches'], 'hostname');
        $this->assertContains('jobs.example.com', $hosts);
        $this->assertContains('careers.example.com', $hosts);
    }

    public function test_exits_non_zero_on_no_matches(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id]);

        $exit = Artisan::call('dply:fleet:domain-find', [
            'hostname' => 'missing.example.com',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame([], $decoded['matches']);
    }

    public function test_rejects_empty_hostname(): void
    {
        $exit = Artisan::call('dply:fleet:domain-find', ['hostname' => '']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cannot be empty', $output);
    }
}
