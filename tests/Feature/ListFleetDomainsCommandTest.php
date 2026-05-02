<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ListFleetDomainsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_all_domains_in_fleet(): void
    {
        $server = Server::factory()->create(['name' => 'web-1']);
        $a = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php', 'slug' => 'alpha']);
        $b = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'node', 'slug' => 'bravo']);
        $a->domains()->create(['hostname' => 'a.example.com', 'is_primary' => true]);
        $b->domains()->create(['hostname' => 'b.example.com', 'is_primary' => true]);
        $b->domains()->create(['hostname' => 'b-alias.example.com', 'is_primary' => false]);

        Artisan::call('dply:fleet:domain-list', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(3, $decoded['count']);
        $hosts = array_column($decoded['domains'], 'hostname');
        $this->assertSame(['a.example.com', 'b-alias.example.com', 'b.example.com'], $hosts);
    }

    public function test_primary_only_filter(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true]);
        $site->domains()->create(['hostname' => 'alias.example.com', 'is_primary' => false]);

        Artisan::call('dply:fleet:domain-list', [
            '--primary-only' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['count']);
        $this->assertSame('primary.example.com', $decoded['domains'][0]['hostname']);
    }

    public function test_runtime_filter(): void
    {
        $server = Server::factory()->create();
        $php = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php', 'slug' => 'php-app']);
        $node = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'node', 'slug' => 'node-app']);
        $php->domains()->create(['hostname' => 'php.example.com']);
        $node->domains()->create(['hostname' => 'node.example.com']);

        Artisan::call('dply:fleet:domain-list', [
            '--runtime' => 'node',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['count']);
        $this->assertSame('node.example.com', $decoded['domains'][0]['hostname']);
    }

    public function test_includes_server_context(): void
    {
        $server = Server::factory()->create(['name' => 'prod-1', 'ip_address' => '203.0.113.5']);
        $site = Site::factory()->create(['server_id' => $server->id, 'name' => 'jobs-app']);
        $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

        Artisan::call('dply:fleet:domain-list', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $row = $decoded['domains'][0];
        $this->assertSame('jobs-app', $row['site_name']);
        $this->assertSame('prod-1', $row['server_name']);
        $this->assertSame('203.0.113.5', $row['server_ip']);
    }

    public function test_empty_fleet_returns_zero_count(): void
    {
        Artisan::call('dply:fleet:domain-list', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $decoded['count']);
    }
}
