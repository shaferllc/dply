<?php

declare(strict_types=1);

namespace Tests\Feature\CliSearchCommandTest;
use Illuminate\Support\Facades\Artisan;
test('finds commands by name keyword', function () {
    Artisan::call('dply:cli-search', [
        'keyword' => 'env',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    $names = array_column($decoded['matches'], 'name');
    expect($names)->toContain('dply:site:env-set');
    expect($names)->toContain('dply:site:env-list');
});
test('finds by description match', function () {
    // dply:fleet:running-deploys has "in-progress" in its description but
    // not in its name.
    Artisan::call('dply:cli-search', [
        'keyword' => 'in-progress',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    $names = array_column($decoded['matches'], 'name');
    expect($names)->toContain('dply:fleet:running-deploys');
});
test('names only skips description matches', function () {
    // First: search for "in-progress" — matches a description.
    Artisan::call('dply:cli-search', [
        'keyword' => 'in-progress',
        '--json' => true,
    ]);
    $decodedAll = json_decode(Artisan::output(), true);

    // With --names-only, no command name contains "in-progress".
    Artisan::call('dply:cli-search', [
        'keyword' => 'in-progress',
        '--names-only' => true,
        '--json' => true,
    ]);
    $decodedNames = json_decode(Artisan::output(), true);

    expect($decodedAll['count'])->toBeGreaterThan(0);
    expect($decodedNames['count'])->toBe(0);
});
test('alternation regex works', function () {
    Artisan::call('dply:cli-search', [
        'keyword' => 'rename|set-runtime',
        '--names-only' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    $names = array_column($decoded['matches'], 'name');
    expect($names)->toContain('dply:site:rename');
    expect($names)->toContain('dply:server:rename');
    expect($names)->toContain('dply:site:set-runtime');
});
test('no matches returns failure', function () {
    $exit = Artisan::call('dply:cli-search', [
        'keyword' => 'this-cannot-possibly-match-zzqzz',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('No dply commands match', $output);
});
test('only dply namespaced commands appear', function () {
    // "list" is a Laravel built-in — but our search is restricted to dply:*.
    Artisan::call('dply:cli-search', [
        'keyword' => 'list',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    foreach ($decoded['matches'] as $m) {
        expect($m['name'])->toStartWith('dply:');
    }
});
test('empty keyword is rejected', function () {
    $exit = Artisan::call('dply:cli-search', ['keyword' => '']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('cannot be empty', $output);
});
