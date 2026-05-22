<?php

declare(strict_types=1);

namespace Tests\Feature\ListEnginesCommandTest;
use Illuminate\Support\Facades\Artisan;
test('command lists engines with packages', function () {
    $exit = Artisan::call('dply:list-engines');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Database engines managed by dply', $output);
    $this->assertStringContainsString('postgres17', $output);
    $this->assertStringContainsString('mysql84', $output);
    $this->assertStringContainsString('mariadb114', $output);
    $this->assertStringContainsString('sqlite3', $output);
    $this->assertStringContainsString('postgresql-17', $output);
});
test('command emits json', function () {
    $exit = Artisan::call('dply:list-engines', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded['engines'])->not->toBeEmpty();

    $byEngine = collect($decoded['engines'])->keyBy('engine');
    expect($byEngine)->toHaveKey('postgres17');
    expect($byEngine)->toHaveKey('mysql84');
    $this->assertStringContainsString('PostgreSQL', $byEngine['postgres17']['label']);
    $this->assertStringContainsString('postgresql-17', $byEngine['postgres17']['package']);
});
