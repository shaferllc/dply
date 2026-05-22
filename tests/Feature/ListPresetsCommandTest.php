<?php

declare(strict_types=1);

namespace Tests\Feature\ListPresetsCommandTest;

use Illuminate\Support\Facades\Artisan;

test('command lists all presets with featured marker', function () {
    $exit = Artisan::call('dply:list-presets');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Server-create wizard presets', $output);

    // All eight presets in the v1 list show up.
    foreach (['laravel', 'rails', 'nextjs', 'django', 'polyglot', 'static', 'database', 'custom'] as $id) {
        $this->assertStringContainsString($id, $output);
    }

    // Star marker appears for at least one featured preset.
    $this->assertStringContainsString('★', $output);
});
test('command emits json with full preset data', function () {
    $exit = Artisan::call('dply:list-presets', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded['presets'])->toHaveCount(9);

    $byId = collect($decoded['presets'])->keyBy('id');
    expect($byId['polyglot']['featured'])->toBeTrue();
    expect($byId['polyglot']['php_version'])->toBe('8.4');
    expect(array_keys($byId['polyglot']['runtimes']))->toEqualCanonicalizing(['node', 'python', 'ruby', 'go']);
    expect($byId['custom']['role'])->toBe('plain');
});
test('id flag renders full meta for one preset', function () {
    $exit = Artisan::call('dply:list-presets', ['--id' => 'polyglot']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Polyglot host', $output);
    $this->assertStringContainsString('runtime: node', $output);
    $this->assertStringContainsString('runtime: python', $output);
});
test('id flag with json includes server meta payload', function () {
    $exit = Artisan::call('dply:list-presets', [
        '--id' => 'laravel',
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['preset']['id'])->toBe('laravel');
    expect($decoded['server_meta']['preset'])->toBe('laravel');
    expect($decoded['server_meta']['database'])->toBe('mysql84');
});
test('id flag fails for unknown preset', function () {
    $exit = Artisan::call('dply:list-presets', ['--id' => 'nope']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Preset not found', $output);
});
