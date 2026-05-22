<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RenameServerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_renames_server(): void
    {
        $server = Server::factory()->create(['name' => 'web-1']);

        $exit = Artisan::call('dply:server:rename', [
            'server' => $server->id,
            'new-name' => 'db-1',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('db-1', $server->fresh()->name);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $server = Server::factory()->create(['name' => 'web-1']);

        Artisan::call('dply:server:rename', [
            'server' => $server->id,
            'new-name' => 'db-1',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['dry_run']);
        $this->assertSame('web-1', $server->fresh()->name);
    }

    public function test_no_op_when_already_named_correctly(): void
    {
        $server = Server::factory()->create(['name' => 'web-1']);

        $exit = Artisan::call('dply:server:rename', [
            'server' => $server->id,
            'new-name' => 'web-1',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('already has that name', $output);
    }

    public function test_resolves_server_by_name(): void
    {
        $server = Server::factory()->create(['name' => 'web-1']);

        Artisan::call('dply:server:rename', [
            'server' => 'web-1',
            'new-name' => 'web-2',
        ]);

        $this->assertSame('web-2', $server->fresh()->name);
    }

    public function test_fails_when_server_not_found(): void
    {
        $exit = Artisan::call('dply:server:rename', [
            'server' => 'nope',
            'new-name' => 'foo',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Server not found', $output);
    }

    public function test_rejects_empty_new_name(): void
    {
        $server = Server::factory()->create(['name' => 'web-1']);

        $exit = Artisan::call('dply:server:rename', [
            'server' => $server->id,
            'new-name' => '   ',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cannot be empty', $output);
    }
}
