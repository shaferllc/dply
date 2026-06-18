<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Logs\Services\ClickHouseClient;
use Illuminate\Console\Command;

/**
 * Creates / reconciles the ClickHouse schema for the dply Logs store. Idempotent
 * (CREATE ... IF NOT EXISTS), so it's safe to run on deploy. The aggregator
 * bulk-inserts into this table; Laravel only reads it. See docs/SERVER_LOGS_ADDON.md.
 *
 *   php artisan dply:logs:schema-sync          # apply
 *   php artisan dply:logs:schema-sync --print  # show the DDL without running it
 */
class SyncLogStoreSchemaCommand extends Command
{
    protected $signature = 'dply:logs:schema-sync {--print : Print the DDL instead of executing it}';

    protected $description = 'Create/reconcile the ClickHouse schema for the dply Logs store';

    public function handle(ClickHouseClient $client): int
    {
        $database = $client->database();
        $table = $client->qualifiedTable();
        $retentionDays = max(1, (int) config('server_logs.clickhouse.retention_days', 7));

        $createDatabase = "CREATE DATABASE IF NOT EXISTS {$database}";

        // Shared multi-tenant table. Tenant isolation is enforced at READ time
        // (every query filters org_id); ORDER BY puts org_id/server_id first so
        // those filters prune granules. tokenbf index gives cheap substring-ish
        // search on message. The TTL is a COLUMN EXPRESSION — each row expires
        // per its own `retention_days` (stamped at the aggregator from the org's
        // plan; default below for rows that predate / don't carry a policy). This
        // is what makes per-tier retention possible on one shared table (PR B2).
        $createTable = <<<SQL
        CREATE TABLE IF NOT EXISTS {$table}
        (
            org_id         String,
            server_id      String,
            site_id        String DEFAULT '',
            source         LowCardinality(String) DEFAULT '',
            unit           LowCardinality(String) DEFAULT '',
            level          LowCardinality(String) DEFAULT '',
            host           String DEFAULT '',
            message        String,
            retention_days UInt16 DEFAULT {$retentionDays},
            timestamp      DateTime64(3) DEFAULT now64(3),
            ingested_at    DateTime64(3) DEFAULT now64(3),
            INDEX idx_message message TYPE tokenbf_v1(32768, 3, 0) GRANULARITY 4
        )
        ENGINE = MergeTree
        PARTITION BY toDate(timestamp)
        ORDER BY (org_id, server_id, timestamp)
        TTL toDateTime(timestamp) + toIntervalDay(retention_days)
        SETTINGS index_granularity = 8192
        SQL;

        // Online, idempotent upgrade for a table created before per-row TTL: add
        // the column (no-op if present) then point the TTL at it. ClickHouse
        // applies both without a rewrite; existing rows take the DEFAULT.
        $addColumn = "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS retention_days UInt16 DEFAULT {$retentionDays}";
        $modifyTtl = "ALTER TABLE {$table} MODIFY TTL toDateTime(timestamp) + toIntervalDay(retention_days)";

        if ($this->option('print')) {
            $this->line($createDatabase.';');
            $this->newLine();
            $this->line($createTable.';');
            $this->newLine();
            $this->line($addColumn.';');
            $this->line($modifyTtl.';');

            return self::SUCCESS;
        }

        if (! $client->ping()) {
            $this->error('Cannot reach ClickHouse. Check config/server_logs.php → clickhouse.* '
                .'(local dev: docker compose -f docker-compose.clickhouse.yml up -d).');

            return self::FAILURE;
        }

        $this->info("Creating database {$database} …");
        $client->statement($createDatabase);

        $this->info("Creating table {$table} (per-row TTL; default {$retentionDays}d) …");
        $client->statement($createTable);

        $this->info('Reconciling retention_days column + per-row TTL …');
        $client->statement($addColumn);
        $client->statement($modifyTtl);

        $this->info('ClickHouse log store schema is in sync.');

        return self::SUCCESS;
    }
}
