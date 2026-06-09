<?php

declare(strict_types=1);

namespace Tests\Feature\ListFrameworksCommandTest;

use Illuminate\Support\Facades\Artisan;

test('lists all runtimes and frameworks', function () {
    Artisan::call('dply:list-frameworks', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['frameworks_by_runtime'])->toHaveKey('php');
    expect($decoded['frameworks_by_runtime']['php'])->toHaveKey('laravel');
    expect($decoded['frameworks_by_runtime'])->toHaveKey('node');
    expect($decoded['frameworks_by_runtime']['node'])->toHaveKey('next');
    expect($decoded['frameworks_by_runtime'])->toHaveKey('python');
    expect($decoded['frameworks_by_runtime']['python'])->toHaveKey('django');
});
test('runtime filter narrows output', function () {
    Artisan::call('dply:list-frameworks', [
        '--runtime' => 'ruby',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect(array_keys($decoded['frameworks_by_runtime']))->toBe(['ruby']);
    expect($decoded['frameworks_by_runtime']['ruby'])->toHaveKey('rails');
});
test('unknown runtime returns failure', function () {
    $exit = Artisan::call('dply:list-frameworks', ['--runtime' => 'cobol']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Unknown runtime', $output);
});
test('human output renders all runtimes', function () {
    $exit = Artisan::call('dply:list-frameworks');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Php', $output);
    $this->assertStringContainsString('laravel', $output);
    $this->assertStringContainsString('Node', $output);
    $this->assertStringContainsString('next', $output);
    $this->assertStringContainsString('Static', $output);
});
