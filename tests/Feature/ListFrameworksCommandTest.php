<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ListFrameworksCommandTest extends TestCase
{
    public function test_lists_all_runtimes_and_frameworks(): void
    {
        Artisan::call('dply:list-frameworks', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertArrayHasKey('php', $decoded['frameworks_by_runtime']);
        $this->assertArrayHasKey('laravel', $decoded['frameworks_by_runtime']['php']);
        $this->assertArrayHasKey('node', $decoded['frameworks_by_runtime']);
        $this->assertArrayHasKey('next', $decoded['frameworks_by_runtime']['node']);
        $this->assertArrayHasKey('python', $decoded['frameworks_by_runtime']);
        $this->assertArrayHasKey('django', $decoded['frameworks_by_runtime']['python']);
    }

    public function test_runtime_filter_narrows_output(): void
    {
        Artisan::call('dply:list-frameworks', [
            '--runtime' => 'ruby',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(['ruby'], array_keys($decoded['frameworks_by_runtime']));
        $this->assertArrayHasKey('rails', $decoded['frameworks_by_runtime']['ruby']);
    }

    public function test_unknown_runtime_returns_failure(): void
    {
        $exit = Artisan::call('dply:list-frameworks', ['--runtime' => 'cobol']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown runtime', $output);
    }

    public function test_human_output_renders_all_runtimes(): void
    {
        $exit = Artisan::call('dply:list-frameworks');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Php', $output);
        $this->assertStringContainsString('laravel', $output);
        $this->assertStringContainsString('Node', $output);
        $this->assertStringContainsString('next', $output);
        $this->assertStringContainsString('Static', $output);
    }
}
