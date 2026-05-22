<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ServerPhpOpcacheProfile;
use App\Services\Servers\ServerOpcacheConfigEditor;
use Tests\TestCase;

class ServerOpcacheConfigEditorTest extends TestCase
{
    public function test_renders_disabled_profile_emits_enable_zero(): void
    {
        $profile = $this->profile([
            'enabled' => false,
            'memory_consumption_mb' => 128,
        ]);

        $ini = app(ServerOpcacheConfigEditor::class)->renderIni($profile);

        $this->assertStringContainsString('opcache.enable=0', $ini);
        $this->assertStringContainsString('opcache.enable_cli=0', $ini);
    }

    public function test_renders_enabled_profile_with_overrides(): void
    {
        $profile = $this->profile([
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
    }

    public function test_clamps_memory_to_minimum(): void
    {
        $profile = $this->profile([
            'memory_consumption_mb' => 1,
        ]);

        $ini = app(ServerOpcacheConfigEditor::class)->renderIni($profile);
        $this->assertStringContainsString('opcache.memory_consumption=8', $ini);
    }

    public function test_invalid_jit_falls_back_to_off(): void
    {
        $profile = $this->profile([
            'jit' => 'totally-made-up',
        ]);

        $ini = app(ServerOpcacheConfigEditor::class)->renderIni($profile);
        $this->assertStringContainsString('opcache.jit=0', $ini);
    }

    public function test_target_path_for_version(): void
    {
        $this->assertSame(
            '/etc/php/8.3/mods-available/opcache.ini',
            app(ServerOpcacheConfigEditor::class)->targetPath('8.3'),
        );
    }

    /**
     * Build an unsaved profile with sensible defaults overlaid.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function profile(array $overrides): ServerPhpOpcacheProfile
    {
        $profile = new ServerPhpOpcacheProfile;
        $profile->forceFill(array_merge(
            ServerPhpOpcacheProfile::defaults(),
            ['php_version' => '8.3'],
            $overrides,
        ));

        return $profile;
    }
}
