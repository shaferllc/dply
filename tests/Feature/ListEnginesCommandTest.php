<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ListEnginesCommandTest extends TestCase
{
    public function test_command_lists_engines_with_packages(): void
    {
        $exit = Artisan::call('dply:list-engines');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Database engines managed by dply', $output);
        $this->assertStringContainsString('postgres17', $output);
        $this->assertStringContainsString('mysql84', $output);
        $this->assertStringContainsString('mariadb114', $output);
        $this->assertStringContainsString('sqlite3', $output);
        $this->assertStringContainsString('postgresql-17', $output);
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('dply:list-engines', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded['engines']);

        $byEngine = collect($decoded['engines'])->keyBy('engine');
        $this->assertArrayHasKey('postgres17', $byEngine);
        $this->assertArrayHasKey('mysql84', $byEngine);
        $this->assertStringContainsString('PostgreSQL', $byEngine['postgres17']['label']);
        $this->assertStringContainsString('postgresql-17', $byEngine['postgres17']['package']);
    }
}
