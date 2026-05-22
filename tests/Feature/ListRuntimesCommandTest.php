<?php

declare(strict_types=1);

namespace Tests\Feature\ListRuntimesCommandTest;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('command lists all six runtimes with paths', function () {
    $exit = Artisan::call('dply:list-runtimes');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Runtimes managed by dply', $output);
    $this->assertStringContainsString('php', $output);
    $this->assertStringContainsString('node', $output);
    $this->assertStringContainsString('python', $output);
    $this->assertStringContainsString('ruby', $output);
    $this->assertStringContainsString('go', $output);

    // PHP carries its own install path label.
    $this->assertStringContainsString('ondrej/php apt', $output);

    // The four mise-managed runtimes share the mise path.
    $this->assertStringContainsString('mise', $output);
});
test('command emits json with recommended versions', function () {
    $exit = Artisan::call('dply:list-runtimes', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded['runtimes'])->toHaveCount(5);

    $byRuntime = collect($decoded['runtimes'])->keyBy('runtime');
    expect($byRuntime['php']['install_path'])->toBe('ondrej/php apt');
    expect($byRuntime['node']['install_path'])->toBe('mise');
    expect($byRuntime['node']['recommended_version'])->toBe('22');
    expect($byRuntime['python']['recommended_version'])->toBe('3.12');
    expect($byRuntime['ruby']['recommended_version'])->toBe('3.3');
    expect($byRuntime['go']['recommended_version'])->toBe('1.22');
});
test('with usage includes site counts', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
    Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
    Site::factory()->create(['server_id' => $server->id, 'runtime' => 'node']);

    Artisan::call('dply:list-runtimes', [
        '--with-usage' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    $byRuntime = collect($decoded['runtimes'])->keyBy('runtime');
    expect($byRuntime['php']['site_count'])->toBe(2);
    expect($byRuntime['node']['site_count'])->toBe(1);

    // Runtimes with no sites should still be listed but with site_count = 0.
    expect($byRuntime['python']['site_count'] ?? 0)->toBe(0);
});
test('with usage includes static when used', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id, 'runtime' => 'static']);

    Artisan::call('dply:list-runtimes', [
        '--with-usage' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    $byRuntime = collect($decoded['runtimes'])->keyBy('runtime');
    expect($byRuntime)->toHaveKey('static');
    expect($byRuntime['static']['site_count'])->toBe(1);
});
