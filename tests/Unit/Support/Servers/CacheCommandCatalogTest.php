<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\CacheCommandCatalogTest;
use App\Support\Servers\CacheCommandCatalog;
test('catalog entries have the documented shape', function () {
    foreach (CacheCommandCatalog::respFamily() as $entry) {
        expect(array_keys($entry))->toBe(['name', 'syntax', 'summary', 'group', 'mutating'], 'Catalog entries must keep their documented keys (name, syntax, summary, group, mutating).');
        expect($entry['name'])->not->toBeEmpty();
        expect($entry['syntax'])->not->toBeEmpty();
        expect($entry['summary'])->not->toBeEmpty();
        expect($entry['group'])->not->toBeEmpty();
        expect($entry['mutating'])->toBeBool();
    }
});
test('catalog includes core commands operators expect', function () {
    $names = array_column(CacheCommandCatalog::respFamily(), 'name');

    // If any of these go missing the autocomplete becomes useless — they're the verbs an
    // operator types within the first thirty seconds of opening the REPL.
    foreach (['GET', 'SET', 'DEL', 'KEYS', 'SCAN', 'INFO', 'PING', 'TTL', 'EXPIRE', 'CONFIG GET', 'CLIENT LIST'] as $expected) {
        expect($names)->toContain($expected);
    }
});
test('mutating flag is correct for known commands', function () {
    $byName = [];
    foreach (CacheCommandCatalog::respFamily() as $entry) {
        $byName[$entry['name']] = $entry;
    }

    expect($byName['GET']['mutating'])->toBeFalse('GET must be read-only.');
    expect($byName['INFO']['mutating'])->toBeFalse('INFO must be read-only.');
    expect($byName['KEYS']['mutating'])->toBeFalse('KEYS must be read-only.');
    expect($byName['SET']['mutating'])->toBeTrue('SET must be flagged mutating.');
    expect($byName['DEL']['mutating'])->toBeTrue('DEL must be flagged mutating.');
    expect($byName['FLUSHALL']['mutating'])->toBeTrue('FLUSHALL must be flagged mutating.');
    expect($byName['CONFIG SET']['mutating'])->toBeTrue('CONFIG SET must be flagged mutating.');
});
test('grouped view returns same data partitioned by group', function () {
    $flat = CacheCommandCatalog::respFamily();
    $grouped = CacheCommandCatalog::respFamilyByGroup();

    // Re-flatten the grouped view and compare counts. We don't compare arrays directly
    // because grouped order != flat order.
    $flattenedCount = 0;
    foreach ($grouped as $cmds) {
        $flattenedCount += count($cmds);
    }

    expect($flattenedCount)->toBe(count($flat));
    expect($flattenedCount)->toBeGreaterThan(50, 'Catalog should not have shrunk past a useful size.');
});
