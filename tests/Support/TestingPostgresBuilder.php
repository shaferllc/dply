<?php

namespace Tests\Support;

use Illuminate\Database\Schema\PostgresBuilder;

/**
 * Avoids Laravel's single-statement DROP for every table (~130+ locks), which
 * exceeds Postgres default max_locks_per_transaction during parallel test workers.
 *
 * Batches drops so each statement stays under typical limits (64) without
 * requiring a local Postgres restart.
 */
class TestingPostgresBuilder extends PostgresBuilder
{
    private const DROP_CHUNK_SIZE = 32;

    public function dropAllTables(): void
    {
        $excludedTables = $this->connection->getConfig('dont_drop') ?? ['spatial_ref_sys'];

        $tables = [];

        foreach ($this->getTables($this->getCurrentSchemaListing()) as $table) {
            if (empty(array_intersect([$table['name'], $table['schema_qualified_name']], $excludedTables))) {
                $tables[] = $table['schema_qualified_name'];
            }
        }

        if ($tables === []) {
            return;
        }

        foreach (array_chunk($tables, self::DROP_CHUNK_SIZE) as $chunk) {
            $this->connection->statement(
                $this->grammar->compileDropAllTables($chunk)
            );
        }
    }

    public function dropAllViews(): void
    {
        $views = array_column($this->getViews($this->getCurrentSchemaListing()), 'schema_qualified_name');

        if ($views === []) {
            return;
        }

        foreach (array_chunk($views, self::DROP_CHUNK_SIZE) as $chunk) {
            $this->connection->statement(
                $this->grammar->compileDropAllViews($chunk)
            );
        }
    }

    public function dropAllTypes(): void
    {
        $types = [];
        $domains = [];

        foreach ($this->getTypes($this->getCurrentSchemaListing()) as $type) {
            if (! $type['implicit']) {
                if ($type['type'] === 'domain') {
                    $domains[] = $type['schema_qualified_name'];
                } else {
                    $types[] = $type['schema_qualified_name'];
                }
            }
        }

        foreach (array_chunk($types, self::DROP_CHUNK_SIZE) as $chunk) {
            if ($chunk !== []) {
                $this->connection->statement($this->grammar->compileDropAllTypes($chunk));
            }
        }

        foreach (array_chunk($domains, self::DROP_CHUNK_SIZE) as $chunk) {
            if ($chunk !== []) {
                $this->connection->statement($this->grammar->compileDropAllDomains($chunk));
            }
        }
    }
}
