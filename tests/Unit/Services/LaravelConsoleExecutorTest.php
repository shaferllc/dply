<?php

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Services\Sites\LaravelConsoleExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaravelConsoleExecutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_assert_safe_artisan_argv_rejects_shell_metacharacters(): void
    {
        $executor = app(LaravelConsoleExecutor::class);

        $executor->assertSafeArtisanArgv('cache:clear');

        $this->expectException(\InvalidArgumentException::class);
        $executor->assertSafeArtisanArgv('cache:clear; rm -rf /');
    }

    public function test_preset_command_recognized(): void
    {
        $executor = app(LaravelConsoleExecutor::class);

        $this->assertTrue($executor->isPresetCommand('cache:clear'));
        $this->assertFalse($executor->isPresetCommand('made:up'));
    }

    public function test_custom_commands_read_from_meta(): void
    {
        $site = Site::factory()->create([
            'meta' => [
                'vm_runtime' => [
                    'detected' => ['framework' => 'laravel', 'language' => 'php'],
                ],
                'laravel_console' => [
                    'custom_commands' => ['migrate --force', 'db:seed'],
                ],
            ],
        ]);

        $executor = app(LaravelConsoleExecutor::class);

        $this->assertSame(['migrate --force', 'db:seed'], $executor->customCommands($site->fresh()));
    }
}
