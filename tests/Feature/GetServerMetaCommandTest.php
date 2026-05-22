<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GetServerMetaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prints_string_value_plain(): void
    {
        $server = Server::factory()->create([
            'meta' => ['webserver' => 'nginx'],
        ]);

        $exit = Artisan::call('dply:server:meta-get', [
            'server' => $server->id,
            'key' => 'webserver',
        ]);
        $output = trim(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('nginx', $output);
    }

    public function test_prints_nested_value_via_dot_notation(): void
    {
        $server = Server::factory()->create([
            'meta' => ['runtime_defaults' => ['node' => '22.1.0']],
        ]);

        Artisan::call('dply:server:meta-get', [
            'server' => $server->id,
            'key' => 'runtime_defaults.node',
        ]);
        $output = trim(Artisan::output());

        $this->assertSame('22.1.0', $output);
    }

    public function test_prints_json_for_array_values(): void
    {
        $server = Server::factory()->create([
            'meta' => ['runtime_defaults' => ['node' => '22', 'python' => '3.12']],
        ]);

        Artisan::call('dply:server:meta-get', [
            'server' => $server->id,
            'key' => 'runtime_defaults',
        ]);
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(['node' => '22', 'python' => '3.12'], $decoded);
    }

    public function test_dumps_full_meta_when_no_key_given(): void
    {
        $server = Server::factory()->create([
            'meta' => ['webserver' => 'nginx', 'php_version' => '8.4'],
        ]);

        Artisan::call('dply:server:meta-get', ['server' => $server->id]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame('nginx', $decoded['webserver']);
        $this->assertSame('8.4', $decoded['php_version']);
    }

    public function test_json_output_wraps_payload(): void
    {
        $server = Server::factory()->create(['meta' => ['webserver' => 'nginx']]);

        Artisan::call('dply:server:meta-get', [
            'server' => $server->id,
            'key' => 'webserver',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('webserver', $decoded['key']);
        $this->assertSame('nginx', $decoded['value']);
        $this->assertTrue($decoded['present']);
    }

    public function test_exits_non_zero_when_key_missing(): void
    {
        $server = Server::factory()->create(['meta' => ['webserver' => 'nginx']]);

        $exit = Artisan::call('dply:server:meta-get', [
            'server' => $server->id,
            'key' => 'nonexistent',
        ]);

        $this->assertSame(1, $exit);
    }

    public function test_json_with_missing_key_includes_present_false(): void
    {
        $server = Server::factory()->create(['meta' => ['webserver' => 'nginx']]);

        Artisan::call('dply:server:meta-get', [
            'server' => $server->id,
            'key' => 'nonexistent',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertFalse($decoded['present']);
        $this->assertNull($decoded['value']);
    }

    public function test_command_fails_when_server_not_found(): void
    {
        $exit = Artisan::call('dply:server:meta-get', [
            'server' => 'nope',
            'key' => 'foo',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Server not found', $output);
    }
}
