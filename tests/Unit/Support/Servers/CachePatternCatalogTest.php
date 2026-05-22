<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\CachePatternCatalogTest;
use App\Support\Servers\CachePatternCatalog;
test('catalog entries have the documented shape', function () {
    foreach (CachePatternCatalog::all() as $entry) {
        expect(array_keys($entry))->toBe(['pattern', 'description', 'group'], 'Pattern catalog entries must keep the documented keys.');
        expect($entry['pattern'])->not->toBeEmpty();
        expect($entry['description'])->not->toBeEmpty();
        expect($entry['group'])->not->toBeEmpty();
    }
});
test('catalog includes essential starters', function () {
    $patterns = array_column(CachePatternCatalog::all(), 'pattern');

    // These are the patterns operators reach for first — losing them would defeat the
    // point of the autocomplete.
    foreach (['*', 'session:*', 'cache:*'] as $essential) {
        expect($patterns)->toContain($essential, "Catalog is missing the {$essential} pattern.");
    }
});
test('grouped view partitions the full catalog', function () {
    $flat = CachePatternCatalog::all();
    $grouped = CachePatternCatalog::byGroup();

    $flattenedCount = 0;
    foreach ($grouped as $entries) {
        $flattenedCount += count($entries);
    }
    expect($flattenedCount)->toBe(count($flat));
    expect($grouped)->not->toBeEmpty();
});
