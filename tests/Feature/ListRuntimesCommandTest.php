<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ListRuntimesCommandTest extends TestCase
{
    public function test_command_lists_all_six_runtimes_with_paths(): void
    {
        $exit = Artisan::call('dply:list-runtimes');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
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
    }

    public function test_command_emits_json_with_recommended_versions(): void
    {
        $exit = Artisan::call('dply:list-runtimes', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertCount(5, $decoded['runtimes']);

        $byRuntime = collect($decoded['runtimes'])->keyBy('runtime');
        $this->assertSame('ondrej/php apt', $byRuntime['php']['install_path']);
        $this->assertSame('mise', $byRuntime['node']['install_path']);
        $this->assertSame('22', $byRuntime['node']['recommended_version']);
        $this->assertSame('3.12', $byRuntime['python']['recommended_version']);
        $this->assertSame('3.3', $byRuntime['ruby']['recommended_version']);
        $this->assertSame('1.22', $byRuntime['go']['recommended_version']);
    }
}
