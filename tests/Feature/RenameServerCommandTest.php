<?php

declare(strict_types=1);

namespace Tests\Feature\RenameServerCommandTest;
use App\Models\Server;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('renames server', function () {
    $server = Server::factory()->create(['name' => 'web-1']);

    $exit = Artisan::call('dply:server:rename', [
        'server' => $server->id,
        'new-name' => 'db-1',
    ]);

    expect($exit)->toBe(0);
    expect($server->fresh()->name)->toBe('db-1');
});
test('dry run does not persist', function () {
    $server = Server::factory()->create(['name' => 'web-1']);

    Artisan::call('dply:server:rename', [
        'server' => $server->id,
        'new-name' => 'db-1',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['dry_run'])->toBeTrue();
    expect($server->fresh()->name)->toBe('web-1');
});
test('no op when already named correctly', function () {
    $server = Server::factory()->create(['name' => 'web-1']);

    $exit = Artisan::call('dply:server:rename', [
        'server' => $server->id,
        'new-name' => 'web-1',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('already has that name', $output);
});
test('resolves server by name', function () {
    $server = Server::factory()->create(['name' => 'web-1']);

    Artisan::call('dply:server:rename', [
        'server' => 'web-1',
        'new-name' => 'web-2',
    ]);

    expect($server->fresh()->name)->toBe('web-2');
});
test('fails when server not found', function () {
    $exit = Artisan::call('dply:server:rename', [
        'server' => 'nope',
        'new-name' => 'foo',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Server not found', $output);
});
test('rejects empty new name', function () {
    $server = Server::factory()->create(['name' => 'web-1']);

    $exit = Artisan::call('dply:server:rename', [
        'server' => $server->id,
        'new-name' => '   ',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('cannot be empty', $output);
});
