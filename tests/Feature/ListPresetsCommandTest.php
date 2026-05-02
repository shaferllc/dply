<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ListPresetsCommandTest extends TestCase
{
    public function test_command_lists_all_presets_with_featured_marker(): void
    {
        $exit = Artisan::call('dply:list-presets');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Server-create wizard presets', $output);
        // All eight presets in the v1 list show up.
        foreach (['laravel', 'rails', 'nextjs', 'django', 'polyglot', 'static', 'database', 'custom'] as $id) {
            $this->assertStringContainsString($id, $output);
        }
        // Star marker appears for at least one featured preset.
        $this->assertStringContainsString('★', $output);
    }

    public function test_command_emits_json_with_full_preset_data(): void
    {
        $exit = Artisan::call('dply:list-presets', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertCount(8, $decoded['presets']);

        $byId = collect($decoded['presets'])->keyBy('id');
        $this->assertTrue($byId['polyglot']['featured']);
        $this->assertSame('8.4', $byId['polyglot']['php_version']);
        $this->assertEqualsCanonicalizing(
            ['node', 'python', 'ruby', 'go'],
            array_keys($byId['polyglot']['runtimes']),
        );
        $this->assertSame('plain', $byId['custom']['role']);
    }

    public function test_id_flag_renders_full_meta_for_one_preset(): void
    {
        $exit = Artisan::call('dply:list-presets', ['--id' => 'polyglot']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Polyglot host', $output);
        $this->assertStringContainsString('runtime: node', $output);
        $this->assertStringContainsString('runtime: python', $output);
    }

    public function test_id_flag_with_json_includes_server_meta_payload(): void
    {
        $exit = Artisan::call('dply:list-presets', [
            '--id' => 'laravel',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertSame('laravel', $decoded['preset']['id']);
        $this->assertSame('laravel', $decoded['server_meta']['preset']);
        $this->assertSame('mysql84', $decoded['server_meta']['database']);
    }

    public function test_id_flag_fails_for_unknown_preset(): void
    {
        $exit = Artisan::call('dply:list-presets', ['--id' => 'nope']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Preset not found', $output);
    }
}
