<?php

declare(strict_types=1);

namespace Tests\Feature\SetSiteRuntimeCommandTest;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('command updates runtime and version', function () {
    $site = makeSite(['runtime' => 'php', 'runtime_version' => '8.2']);

    $exit = Artisan::call('dply:site:set-runtime', [
        'site' => $site->slug,
        '--runtime' => 'node',
        '--runtime-version' => '20.10.0',
    ]);

    expect($exit)->toBe(0);
    $site->refresh();
    expect($site->runtime)->toBe('node');
    expect($site->runtime_version)->toBe('20.10.0');
});
test('command updates build start port', function () {
    $site = makeSite(['runtime' => 'node']);

    Artisan::call('dply:site:set-runtime', [
        'site' => $site->slug,
        '--build' => 'npm run build',
        '--start' => 'node server.js',
        '--port' => '3000',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['dry_run'])->toBeFalse();
    expect($decoded['changes'])->toHaveKey('build_command');
    expect($decoded['changes'])->toHaveKey('start_command');
    expect($decoded['changes'])->toHaveKey('internal_port');

    $site->refresh();
    expect($site->build_command)->toBe('npm run build');
    expect($site->start_command)->toBe('node server.js');
    expect($site->internal_port)->toBe(3000);
});
test('dry run does not persist', function () {
    $site = makeSite(['runtime' => 'php']);

    Artisan::call('dply:site:set-runtime', [
        'site' => $site->slug,
        '--runtime' => 'node',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['dry_run'])->toBeTrue();
    expect($site->fresh()->runtime)->toBe('php');
});
test('unset engine clears database engine', function () {
    $site = makeSite(['runtime' => 'static', 'database_engine' => 'postgres']);

    Artisan::call('dply:site:set-runtime', [
        'site' => $site->slug,
        '--unset-engine' => true,
    ]);

    expect($site->fresh()->database_engine)->toBeNull();
});
test('engine and unset engine are mutually exclusive', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:set-runtime', [
        'site' => $site->slug,
        '--engine' => 'mysql',
        '--unset-engine' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('mutually exclusive', $output);
});
test('command rejects unknown runtime', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:set-runtime', [
        'site' => $site->slug,
        '--runtime' => 'cobol',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Unknown runtime', $output);
});
test('command rejects invalid port', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:set-runtime', [
        'site' => $site->slug,
        '--port' => '99999',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Invalid port', $output);
});
test('command fails when no changes requested', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:set-runtime', ['site' => $site->slug]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('No changes requested', $output);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:set-runtime', [
        'site' => 'nope',
        '--runtime' => 'node',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
/**
 * @param  array<string, mixed>  $attrs
 */
function makeSite(array $attrs = []): Site
{
    $server = Server::factory()->create();

    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'slug' => 'jobs',
    ], $attrs));
}
