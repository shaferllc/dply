<?php

namespace Tests\Unit;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteOctaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_octane_server_defaults_to_swoole(): void
    {
        $site = Site::factory()->create(['meta' => null]);

        $this->assertSame('swoole', $site->fresh()->octaneServer());
    }

    public function test_octane_server_reads_meta_and_invalid_falls_back(): void
    {
        $site = Site::factory()->create([
            'meta' => ['laravel_octane' => ['server' => 'roadrunner']],
        ]);

        $this->assertSame('roadrunner', $site->fresh()->octaneServer());

        $site->update(['meta' => ['laravel_octane' => ['server' => 'bogus']]]);
        $this->assertSame('swoole', $site->fresh()->octaneServer());
    }

    public function test_octane_supervisor_command_uses_port_and_server(): void
    {
        $site = Site::factory()->create([
            'octane_port' => 9001,
            'meta' => ['laravel_octane' => ['server' => 'frankenphp']],
        ]);

        $this->assertSame(
            'php artisan octane:start --server=frankenphp --host=127.0.0.1 --port=9001',
            $site->fresh()->octaneSupervisorCommand()
        );
    }

    public function test_octane_supervisor_command_defaults_port_when_missing(): void
    {
        $site = Site::factory()->create([
            'octane_port' => null,
            'meta' => ['laravel_octane' => ['server' => 'swoole']],
        ]);

        $this->assertSame(
            'php artisan octane:start --server=swoole --host=127.0.0.1 --port=8000',
            $site->fresh()->octaneSupervisorCommand()
        );
    }

    public function test_should_show_octane_runtime_ui_requires_laravel_detection_and_composer_flag(): void
    {
        $noDetection = Site::factory()->create(['meta' => null]);
        $this->assertFalse($noDetection->shouldShowOctaneRuntimeUi());

        $laravelOnly = Site::factory()->create([
            'meta' => [
                'docker_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                    ],
                ],
            ],
        ]);
        $this->assertFalse($laravelOnly->fresh()->shouldShowOctaneRuntimeUi());

        $withOctane = Site::factory()->create([
            'meta' => [
                'docker_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                        'laravel_octane' => true,
                    ],
                ],
            ],
        ]);
        $this->assertTrue($withOctane->fresh()->shouldShowOctaneRuntimeUi());
    }

    public function test_detected_laravel_package_keys_lists_composer_packages(): void
    {
        $site = Site::factory()->create([
            'meta' => [
                'docker_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                        'language' => 'php',
                        'laravel_horizon' => true,
                        'laravel_reverb' => true,
                    ],
                ],
            ],
        ]);

        $this->assertSame(['horizon', 'reverb'], $site->fresh()->detectedLaravelPackageKeys());
    }

    public function test_reverb_supervisor_command_line_uses_meta_or_override(): void
    {
        $site = Site::factory()->create([
            'meta' => ['laravel_reverb' => ['port' => 9090]],
        ]);

        $this->assertSame(
            'php artisan reverb:start --host=0.0.0.0 --port=9090',
            $site->fresh()->reverbSupervisorCommandLine()
        );
        $this->assertSame(
            'php artisan reverb:start --host=0.0.0.0 --port=7777',
            $site->fresh()->reverbSupervisorCommandLine(7777)
        );
    }
}
