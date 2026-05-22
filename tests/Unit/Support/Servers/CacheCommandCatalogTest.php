<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Support\Servers\CacheCommandCatalog;
use Tests\TestCase;

/**
 * Lock the curated REPL catalog: its shape is stable, the bread-and-butter verbs are present,
 * and `mutating` flags don't flip silently (the workspace REPL renders a "mutating" badge that
 * tells operators which commands need the unlock toggle, so this is user-visible signal).
 */
class CacheCommandCatalogTest extends TestCase
{
    public function test_catalog_entries_have_the_documented_shape(): void
    {
        foreach (CacheCommandCatalog::respFamily() as $entry) {
            $this->assertSame(
                ['name', 'syntax', 'summary', 'group', 'mutating'],
                array_keys($entry),
                'Catalog entries must keep their documented keys (name, syntax, summary, group, mutating).',
            );
            $this->assertNotEmpty($entry['name']);
            $this->assertNotEmpty($entry['syntax']);
            $this->assertNotEmpty($entry['summary']);
            $this->assertNotEmpty($entry['group']);
            $this->assertIsBool($entry['mutating']);
        }
    }

    public function test_catalog_includes_core_commands_operators_expect(): void
    {
        $names = array_column(CacheCommandCatalog::respFamily(), 'name');
        // If any of these go missing the autocomplete becomes useless — they're the verbs an
        // operator types within the first thirty seconds of opening the REPL.
        foreach (['GET', 'SET', 'DEL', 'KEYS', 'SCAN', 'INFO', 'PING', 'TTL', 'EXPIRE', 'CONFIG GET', 'CLIENT LIST'] as $expected) {
            $this->assertContains($expected, $names, "Catalog is missing the {$expected} command.");
        }
    }

    public function test_mutating_flag_is_correct_for_known_commands(): void
    {
        $byName = [];
        foreach (CacheCommandCatalog::respFamily() as $entry) {
            $byName[$entry['name']] = $entry;
        }

        $this->assertFalse($byName['GET']['mutating'], 'GET must be read-only.');
        $this->assertFalse($byName['INFO']['mutating'], 'INFO must be read-only.');
        $this->assertFalse($byName['KEYS']['mutating'], 'KEYS must be read-only.');
        $this->assertTrue($byName['SET']['mutating'], 'SET must be flagged mutating.');
        $this->assertTrue($byName['DEL']['mutating'], 'DEL must be flagged mutating.');
        $this->assertTrue($byName['FLUSHALL']['mutating'], 'FLUSHALL must be flagged mutating.');
        $this->assertTrue($byName['CONFIG SET']['mutating'], 'CONFIG SET must be flagged mutating.');
    }

    public function test_grouped_view_returns_same_data_partitioned_by_group(): void
    {
        $flat = CacheCommandCatalog::respFamily();
        $grouped = CacheCommandCatalog::respFamilyByGroup();

        // Re-flatten the grouped view and compare counts. We don't compare arrays directly
        // because grouped order != flat order.
        $flattenedCount = 0;
        foreach ($grouped as $cmds) {
            $flattenedCount += count($cmds);
        }

        $this->assertSame(count($flat), $flattenedCount);
        $this->assertGreaterThan(50, $flattenedCount, 'Catalog should not have shrunk past a useful size.');
    }
}
