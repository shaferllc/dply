<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SetServerMetaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_a_top_level_key(): void
    {
        $server = Server::factory()->create(['meta' => ['webserver' => 'nginx']]);

        // Pass --raw because "8.4" would otherwise auto-parse as a
        // float, and version strings are conventionally stored as
        // strings in meta.
        Artisan::call('dply:server:meta-set', [
            'server' => $server->id,
            'assignment' => 'php_version=8.4',
            '--raw' => true,
        ]);

        $server->refresh();
        $this->assertSame('8.4', $server->meta['php_version']);
        $this->assertSame('nginx', $server->meta['webserver']);
    }

    public function test_sets_a_nested_key_with_dot_notation(): void
    {
        $server = Server::factory()->create(['meta' => []]);

        Artisan::call('dply:server:meta-set', [
            'server' => $server->id,
            'assignment' => 'runtime_defaults.node=22.1.0',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('runtime_defaults.node', $decoded['key']);
        $server->refresh();
        $this->assertSame('22.1.0', $server->meta['runtime_defaults']['node']);
    }

    public function test_auto_parses_json_literals(): void
    {
        $server = Server::factory()->create(['meta' => []]);

        Artisan::call('dply:server:meta-set', [
            'server' => $server->id,
            'assignment' => 'is_production=true',
        ]);
        $server->refresh();
        $this->assertTrue($server->meta['is_production']);

        Artisan::call('dply:server:meta-set', [
            'server' => $server->id,
            'assignment' => 'cpu_count=8',
        ]);
        $server->refresh();
        $this->assertSame(8, $server->meta['cpu_count']);
    }

    public function test_raw_flag_disables_auto_parse(): void
    {
        $server = Server::factory()->create(['meta' => []]);

        Artisan::call('dply:server:meta-set', [
            'server' => $server->id,
            'assignment' => 'flag=true',
            '--raw' => true,
        ]);

        $server->refresh();
        $this->assertSame('true', $server->meta['flag']);
    }

    public function test_unset_removes_a_key(): void
    {
        $server = Server::factory()->create(['meta' => ['webserver' => 'nginx', 'php_version' => '8.4']]);

        Artisan::call('dply:server:meta-set', [
            'server' => $server->id,
            'assignment' => 'php_version=',
            '--unset' => true,
        ]);

        $server->refresh();
        $this->assertArrayNotHasKey('php_version', $server->meta);
        $this->assertSame('nginx', $server->meta['webserver']);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $server = Server::factory()->create(['meta' => ['webserver' => 'nginx']]);

        Artisan::call('dply:server:meta-set', [
            'server' => $server->id,
            'assignment' => 'webserver=apache',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['dry_run']);
        $this->assertSame('nginx', $server->fresh()->meta['webserver']);
    }

    public function test_rejects_invalid_assignment_format(): void
    {
        $server = Server::factory()->create();

        $exit = Artisan::call('dply:server:meta-set', [
            'server' => $server->id,
            'assignment' => 'no-equal',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('key=value', $output);
    }

    public function test_rejects_invalid_key(): void
    {
        $server = Server::factory()->create();

        $exit = Artisan::call('dply:server:meta-set', [
            'server' => $server->id,
            'assignment' => '@bad@key=foo',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Key must match', $output);
    }

    public function test_fails_when_server_not_found(): void
    {
        $exit = Artisan::call('dply:server:meta-set', [
            'server' => 'nope',
            'assignment' => 'foo=bar',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Server not found', $output);
    }
}
