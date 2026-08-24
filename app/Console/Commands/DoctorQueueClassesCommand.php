<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Report which queued job classes this PHP process can actually autoload.
 *
 *   dply:doctor:queue-classes
 *   dply:doctor:queue-classes --json
 *   dply:doctor:queue-classes --prune-missing --force
 *
 * Diagnoses this failure, which is otherwise opaque:
 *
 *   CallQueuedHandler::commandShouldBeDebounced(): The script tried to access
 *   a property on an incomplete object. Please ensure that the class
 *   definition "App\Foo\Bar" ... was loaded _before_ unserialize()
 *
 * That means the worker pulled a payload naming a class it cannot load, so
 * unserialize() handed back __PHP_Incomplete_Class. There are exactly two
 * causes and they need opposite fixes, which is why guessing is expensive:
 *
 *   MISSING — the class is genuinely gone (deleted module, renamed job). The
 *             payload can never run; delete it.
 *   LOADABLE — the class exists HERE but the worker still failed on it, which
 *             means the worker is running different code or a stale
 *             autoloader: deploy, `composer dump-autoload -o`, restart workers.
 *
 * Run this on the box that runs the workers, as the user the workers run as —
 * a result from anywhere else answers a different question.
 *
 * Read-only by default; exits 1 when anything is unloadable.
 */
class DoctorQueueClassesCommand extends Command
{
    protected $signature = 'dply:doctor:queue-classes
        {--prune-missing : Delete failed jobs whose class no longer exists (requires --force)}
        {--force : Required to actually delete}
        {--json : Output as JSON}';

    protected $description = 'Check whether queued/failed job classes can be autoloaded here.';

    public function handle(): int
    {
        $rows = $this->inspect();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($rows);
        }

        if ($rows === []) {
            $this->components->info('No failed jobs to inspect.');

            return self::SUCCESS;
        }

        $this->table(
            ['job class', 'failed jobs', 'loadable here?'],
            array_map(fn (array $r): array => [
                $r['class'],
                $r['count'],
                $r['loadable'] ? 'yes' : 'NO — cannot ever run',
            ], $rows),
        );

        $missing = array_values(array_filter($rows, fn (array $r): bool => ! $r['loadable']));
        $loadable = array_values(array_filter($rows, fn (array $r): bool => $r['loadable']));

        if ($loadable !== []) {
            $this->newLine();
            $this->components->warn(
                'These classes load fine here, so a worker that failed on them is running stale code. '
                .'Deploy, then: composer dump-autoload -o && php artisan queue:restart && php artisan horizon:terminate'
            );
        }

        if ($missing === []) {
            return $this->exitCode($rows);
        }

        $this->newLine();
        $this->components->error(sprintf(
            '%d class(es) do not exist — their payloads are poison and will fail forever.',
            count($missing),
        ));

        if (! $this->option('prune-missing')) {
            $this->components->info('Re-run with --prune-missing --force to delete just those failed jobs.');

            return $this->exitCode($rows);
        }

        if (! $this->option('force')) {
            $this->components->error('--prune-missing deletes rows; pass --force to confirm.');

            return self::FAILURE;
        }

        $deleted = DB::table('failed_jobs')
            ->whereIn('payload->data->commandName', array_column($missing, 'class'))
            ->delete();

        $this->components->info(sprintf('Deleted %d failed job(s) for missing classes.', $deleted));

        return self::SUCCESS;
    }

    /**
     * @param  list<array{class: string, count: int, loadable: bool}>  $rows
     */
    private function exitCode(array $rows): int
    {
        foreach ($rows as $row) {
            if (! $row['loadable']) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Distinct job classes across failed_jobs, with whether this process can
     * load each.
     *
     * `commandName` is read out of the payload rather than `displayName`: the
     * two usually match, but displayName is overridable and commandName is
     * what CallQueuedHandler actually unserializes.
     *
     * @return list<array{class: string, count: int, loadable: bool}>
     */
    private function inspect(): array
    {
        $out = [];

        foreach (DB::table('failed_jobs')->get(['payload']) as $row) {
            $payload = json_decode((string) $row->payload, true);
            $class = is_array($payload)
                ? ($payload['data']['commandName'] ?? $payload['displayName'] ?? null)
                : null;

            if (! is_string($class) || $class === '') {
                continue;
            }

            $out[$class] = ($out[$class] ?? 0) + 1;
        }

        ksort($out);

        return array_map(
            fn (string $class, int $count): array => [
                'class' => $class,
                'count' => $count,
                // class_exists() triggers the autoloader — exactly what the
                // worker does, so this reproduces the worker's own lookup.
                'loadable' => class_exists($class),
            ],
            array_keys($out),
            array_values($out),
        );
    }
}
