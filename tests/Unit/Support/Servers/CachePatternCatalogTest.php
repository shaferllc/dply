<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Support\Servers\CachePatternCatalog;
use Tests\TestCase;

class CachePatternCatalogTest extends TestCase
{
    public function test_catalog_entries_have_the_documented_shape(): void
    {
        foreach (CachePatternCatalog::all() as $entry) {
            $this->assertSame(
                ['pattern', 'description', 'group'],
                array_keys($entry),
                'Pattern catalog entries must keep the documented keys.',
            );
            $this->assertNotEmpty($entry['pattern']);
            $this->assertNotEmpty($entry['description']);
            $this->assertNotEmpty($entry['group']);
        }
    }

    public function test_catalog_includes_essential_starters(): void
    {
        $patterns = array_column(CachePatternCatalog::all(), 'pattern');
        // These are the patterns operators reach for first — losing them would defeat the
        // point of the autocomplete.
        foreach (['*', 'session:*', 'cache:*'] as $essential) {
            $this->assertContains($essential, $patterns, "Catalog is missing the {$essential} pattern.");
        }
    }

    public function test_grouped_view_partitions_the_full_catalog(): void
    {
        $flat = CachePatternCatalog::all();
        $grouped = CachePatternCatalog::byGroup();

        $flattenedCount = 0;
        foreach ($grouped as $entries) {
            $flattenedCount += count($entries);
        }
        $this->assertSame(count($flat), $flattenedCount);
        $this->assertNotEmpty($grouped);
    }
}
