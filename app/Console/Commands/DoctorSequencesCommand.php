<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Find (and optionally repair) Postgres identity sequences that have fallen
 * behind their table's max id.
 *
 *   dply:doctor:sequences
 *   dply:doctor:sequences --json
 *   dply:doctor:sequences --fix --force
 *
 * A sequence drifts when rows are inserted with explicit ids and the sequence
 * is never advanced — overwhelmingly from a `pg_dump`/restore or a manual
 * `COPY` that carried the rows but not the sequence state. The table looks
 * fine until the next insert, which then fails with:
 *
 *   SQLSTATE[23505]: Unique violation: duplicate key value violates unique
 *   constraint "<table>_pkey"  DETAIL: Key (id)=(752) already exists.
 *
 * Read-only by default: it reports drift and exits 1 so a deploy check can
 * fail on it. `--fix` calls setval() to move each stale sequence up to
 * max(id), and requires --force because it writes to the database.
 *
 * Only genuine collisions are reported: a sequence whose next value is
 * already present in the table. A sequence sitting exactly at max(id) is
 * healthy — that is what an ordinary insert leaves behind — and one ahead of
 * max(id) (rows since deleted) is healthy too. setval() here only ever moves a
 * sequence forward.
 */
class DoctorSequencesCommand extends Command
{
    protected $signature = 'dply:doctor:sequences
        {--fix : Advance stale sequences to max(id) (requires --force)}
        {--force : Required to actually write}
        {--json : Output as JSON}';

    protected $description = 'Find Postgres id sequences that have fallen behind max(id).';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->components->error('This command only applies to Postgres connections.');

            return self::FAILURE;
        }

        $rows = $this->drift();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $rows === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($rows === []) {
            $this->components->info('All sequences are ahead of their table max(id).');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf('%d sequence(s) behind max(id):', count($rows)));
        $this->table(
            ['table', 'column', 'sequence', 'last_value', 'max(id)', 'behind by'],
            array_map(fn (array $r): array => [
                $r['table'], $r['column'], $r['sequence'], $r['last_value'], $r['max_id'], $r['behind_by'],
            ], $rows),
        );

        if (! $this->option('fix')) {
            $this->newLine();
            $this->components->info('Re-run with --fix --force to repair.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->components->error('--fix writes to the database; pass --force to confirm.');

            return self::FAILURE;
        }

        foreach ($rows as $row) {
            // setval(..., false) would make the NEXT id equal max_id, which is
            // already taken. The two-arg form makes the next id max_id + 1.
            DB::statement('SELECT setval(?, ?)', [$row['sequence'], $row['max_id']]);
            $this->components->task(sprintf('%s.%s → %d', $row['table'], $row['column'], $row['max_id']), fn (): bool => true);
        }

        $this->components->info(sprintf('Repaired %d sequence(s).', count($rows)));

        return self::SUCCESS;
    }

    /**
     * Every sequence-backed column whose sequence sits at or below the table's
     * current max id.
     *
     * pg_get_serial_sequence() covers both `serial` and `GENERATED ... AS
     * IDENTITY` columns, so this does not care which style a table uses. The
     * max(id) lookup has to be a second, per-table query because the column
     * name is only known once the first query has run.
     *
     * @return list<array{table: string, column: string, sequence: string, last_value: int, max_id: int, behind_by: int}>
     */
    private function drift(): array
    {
        $columns = DB::select(<<<'SQL'
            SELECT c.table_name, c.column_name,
                   pg_get_serial_sequence(quote_ident(c.table_name), c.column_name) AS seq
            FROM information_schema.columns c
            JOIN information_schema.tables t
              ON t.table_name = c.table_name AND t.table_schema = c.table_schema
            WHERE c.table_schema = current_schema()
              AND t.table_type = 'BASE TABLE'
              AND pg_get_serial_sequence(quote_ident(c.table_name), c.column_name) IS NOT NULL
            ORDER BY c.table_name
        SQL);

        $out = [];

        foreach ($columns as $column) {
            $sequence = (string) $column->seq;
            $table = (string) $column->table_name;
            $col = (string) $column->column_name;

            $maxId = DB::selectOne(sprintf(
                'SELECT COALESCE(MAX(%s), 0) AS max_id FROM %s',
                '"'.str_replace('"', '""', $col).'"',
                '"'.str_replace('"', '""', $table).'"',
            ))->max_id ?? 0;

            $maxId = (int) $maxId;
            if ($maxId === 0) {
                continue;
            }

            $state = DB::selectOne('SELECT last_value, is_called FROM '.$sequence);
            $lastValue = (int) ($state->last_value ?? 0);
            $isCalled = (bool) ($state->is_called ?? true);

            // What the NEXT nextval() will hand out. A fresh, never-called
            // sequence reports last_value=1 with is_called=false and returns 1;
            // once called it returns last_value + 1. Drift is only real when
            // that next id is already present in the table — last_value equal
            // to max(id) is the normal healthy state after an ordinary insert.
            $nextId = $isCalled ? $lastValue + 1 : $lastValue;
            if ($nextId > $maxId) {
                continue;
            }

            $out[] = [
                'table' => $table,
                'column' => $col,
                'sequence' => $sequence,
                'last_value' => $lastValue,
                'max_id' => $maxId,
                'behind_by' => $maxId - $nextId + 1,
            ];
        }

        return $out;
    }
}
