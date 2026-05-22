<?php

declare(strict_types=1);

namespace Tests\Unit\ServerOpcacheConfigEditorTest;
use App\Models\ServerPhpOpcacheProfile;
use App\Services\Servers\ServerOpcacheConfigEditor;
test('renders disabled profile emits enable zero', function () {
    $profile = profile([
        'enabled' => false,
        'memory_consumption_mb' => 128,
    ]);

    $ini = app(ServerOpcacheConfigEditor::class)->renderIni($profile);

    $this->assertStringContainsString('opcache.enable=0', $ini);
    $this->assertStringContainsString('opcache.enable_cli=0', $ini);
});
test('renders enabled profile with overrides', function () {
    $profile = profile([
        'enabled' => true,
        'memory_consumption_mb' => 256,
        'interned_strings_buffer_mb' => 32,
        'max_accelerated_files' => 25000,
        'validate_timestamps' => false,
        'revalidate_freq' => 0,
        'jit' => 'tracing',
        'jit_buffer_size_mb' => 64,
    ]);

    $ini = app(ServerOpcacheConfigEditor::class)->renderIni($profile);

    $this->assertStringContainsString('opcache.memory_consumption=256', $ini);
    $this->assertStringContainsString('opcache.interned_strings_buffer=32', $ini);
    $this->assertStringContainsString('opcache.max_accelerated_files=25000', $ini);
    $this->assertStringContainsString('opcache.validate_timestamps=0', $ini);
    $this->assertStringContainsString('opcache.jit=tracing', $ini);
    $this->assertStringContainsString('opcache.jit_buffer_size=64M', $ini);
});
test('clamps memory to minimum', function () {
    $profile = profile([
        'memory_consumption_mb' => 1,
    ]);

    $ini = app(ServerOpcacheConfigEditor::class)->renderIni($profile);
    $this->assertStringContainsString('opcache.memory_consumption=8', $ini);
});
test('invalid jit falls back to off', function () {
    $profile = profile([
        'jit' => 'totally-made-up',
    ]);

    $ini = app(ServerOpcacheConfigEditor::class)->renderIni($profile);
    $this->assertStringContainsString('opcache.jit=0', $ini);
});
test('target path for version', function () {
    expect(app(ServerOpcacheConfigEditor::class)->targetPath('8.3'))->toBe('/etc/php/8.3/mods-available/opcache.ini');
});
/**
 * Build an unsaved profile with sensible defaults overlaid.
 *
 * @param  array<string, mixed>  $overrides
 */
function profile(array $overrides): ServerPhpOpcacheProfile
{
    $profile = new ServerPhpOpcacheProfile;
    $profile->forceFill(array_merge(
        ServerPhpOpcacheProfile::defaults(),
        ['php_version' => '8.3'],
        $overrides,
    ));

    return $profile;
}
