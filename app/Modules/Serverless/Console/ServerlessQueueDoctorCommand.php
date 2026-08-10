<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Console;

use App\Models\Site;
use App\Modules\Serverless\Models\ServerlessFailedJob;
use App\Modules\Serverless\Services\InvokeFunctionTick;
use App\Modules\Serverless\Services\ServerlessQueueBackend;
use App\Modules\Serverless\Services\ServerlessQueuePump;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnose queue processing on a serverless function.
 *
 *   dply:serverless:queue-doctor <site> [--probe] [--json]
 *
 * Queue problems on a function are quiet by design, which is what makes a
 * doctor worth having. The worst of them:
 *
 *   QUEUE_CONNECTION defaults to `sync` in the injected handler when the
 *   app's .env does not set it. On `sync`, dispatched jobs run inline and
 *   nothing is ever enqueued — so every drain reports "processed 0,
 *   remaining 0" and the pump looks perfectly healthy while doing nothing at
 *   all. Nothing errors, nothing is logged, and the panel shows green.
 *
 * The static checks catch that and the other silent misconfigurations before
 * anyone runs a load test. `--probe` then invokes one real queue slot and
 * prints what the function actually reported, which is the only way to
 * confirm the deployed handler is pump-aware.
 *
 * Read-only apart from the probe, which drains at most a few jobs.
 */
class ServerlessQueueDoctorCommand extends Command
{
    protected $signature = 'dply:serverless:queue-doctor
        {site : Site ID, slug, or name}
        {--probe : Invoke one real queue slot and report what the function said}
        {--json : Output the diagnostic as JSON}';

    protected $description = 'Diagnostic: queue configuration, pump state, and a live drain probe for a serverless function.';

    public function handle(ServerlessQueuePump $pump, InvokeFunctionTick $tick): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);

        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if (! $site->usesFunctionsRuntime()) {
            $this->error('Not a serverless function site: '.$site->slug);

            return self::FAILURE;
        }

        $report = $this->compile($site, $pump);

        if ($this->option('probe')) {
            $report['probe'] = $this->probe($site, $pump, $tick);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['problems'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->render($site, $report);

        return $report['problems'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function compile(Site $site, ServerlessQueuePump $pump): array
    {
        $config = $pump->config($site);
        $serverless = $site->serverlessConfig();
        $parsed = (new DotEnvFileParser)->parse((string) $site->env_file_content);
        $env = is_array($parsed['variables'] ?? null) ? $parsed['variables'] : [];

        $deployed = trim((string) ($serverless['action_url'] ?? '')) !== '';

        $problems = [];
        $notes = [];

        // One classifier behind the doctor, the panel, and the pump — so this
        // command can never call a backend healthy that the pump refuses to
        // drain, which would be the worst possible diagnostic.
        $backend = app(ServerlessQueueBackend::class)->classify($site);
        $connection = $backend['connection'] ?? '';

        if (in_array($backend['state'], [ServerlessQueueBackend::STATE_INERT, ServerlessQueueBackend::STATE_UNSHARED], true)) {
            $fix = $backend['fixable_with_redis']
                ? ' A provisioned Redis is online for this function — run the Workers panel\'s "Use the provisioned Redis" action, or set QUEUE_CONNECTION=redis, then redeploy.'
                : ' Provision a Redis cache on the Resources tab (dply will point the queue at it automatically), use a networked database, or use a networked SQLite such as libSQL/Turso.';

            $problems[] = $backend['reason'].$fix.' dply will not drain this function until then.';
        } elseif ($backend['state'] === ServerlessQueueBackend::STATE_UNKNOWN) {
            $notes[] = $backend['reason'];
        } elseif ($connection === 'database') {
            $notes[] = 'Using the database queue — the app needs a `jobs` table (php artisan queue:table + migrate) and a database reachable from the function.';
        }

        /**
         * The gaps that are NOT the queue, and are the likeliest way this
         * product still disappoints someone.
         *
         * Three of Laravel's most-used queue features are backed by the
         * CACHE, not the queue, and the failed-job store defaults to the
         * app's own database. Fixing the queue and leaving these silent
         * reproduces — one layer up, and worse, because WithoutOverlapping
         * *looks* like it works — the exact failure class this command was
         * written to eliminate.
         */
        // When dply Queue is wired, the injected handler registers a shared
        // lock store and a server-side failed-job provider, so both gaps are
        // closed rather than merely reported.
        $dplyQueueWired = trim((string) ($env['DPLY_QUEUE_URL'] ?? '')) !== ''
            && trim((string) ($env['DPLY_QUEUE_SECRET'] ?? '')) !== '';

        $cacheStore = trim((string) ($env['CACHE_STORE'] ?? $env['CACHE_DRIVER'] ?? ''));
        $cacheIsPerContainer = $cacheStore === '' || in_array($cacheStore, ['array', 'file'], true);

        if ($cacheIsPerContainer && ! $dplyQueueWired) {
            $notes[] = 'The cache store is '.($cacheStore === '' ? 'unset (the handler defaults it to `array`)' : '`'.$cacheStore.'`')
                .', which is per-container on a function. `ShouldBeUnique`, `WithoutOverlapping`, and `RateLimited` are backed by the cache, not the queue — with a per-container store they silently do nothing while appearing to work. Point CACHE_STORE at redis, or move this function to dply Queue, which ships a shared lock store.';
        }

        $dbConnection = trim((string) ($env['DB_CONNECTION'] ?? ''));

        if (($dbConnection === '' || $dbConnection === 'sqlite') && ! $dplyQueueWired) {
            $notes[] = 'Failed jobs are written to the app\'s own database, which here is '
                .($dbConnection === '' ? 'unset (Laravel defaults to SQLite)' : 'SQLite')
                .' — a per-container file that vanishes with the container. A job that exhausts its attempts will disappear without a trace. Use a networked database, or dply Queue, whose failures are recorded server-side.';
        }

        if (! $deployed) {
            $problems[] = 'The function has never deployed — there is no invocation URL to drain against.';
        }

        if (! $config['enabled']) {
            $problems[] = 'Background processing is off for this function. Enable it on the Workers panel, or the pump will never open a slot.';
        }

        // Without a wake URL the pump only ever runs on the one-minute
        // safety-net tick — it works, but the latency win is absent.
        $wakeUrl = trim((string) ($env['DPLY_QUEUE_WAKE_URL'] ?? ''));
        if ($wakeUrl === '') {
            $notes[] = 'No DPLY_QUEUE_WAKE_URL in the deployed env: queue draining falls back to the one-minute tick. Set DPLY_PUBLIC_APP_URL and redeploy for immediate draining.';
        }

        if (trim((string) ($env['DPLY_COMMAND_SECRET'] ?? '')) === '') {
            $problems[] = 'No DPLY_COMMAND_SECRET in the deployed env — dply cannot authenticate a drain against this function. Redeploy to bake it in.';
        }

        return [
            'site' => ['id' => $site->id, 'slug' => $site->slug, 'name' => $site->name],
            'queue_connection' => $connection !== '' ? $connection : null,
            'queue_backend_state' => $backend['state'],
            'deployed' => $deployed,
            'pump' => [
                'enabled' => $config['enabled'],
                'max_concurrency' => $config['max_concurrency'],
                'active_slots' => $pump->activeSlots($site),
                'queue' => $config['queue'] !== '' ? $config['queue'] : null,
                'slot_max_time' => $config['slot_max_time'],
            ],
            'failed_jobs' => [
                'total' => ServerlessFailedJob::query()->where('site_id', $site->id)->count(),
                'awaiting_retry' => ServerlessFailedJob::query()
                    ->where('site_id', $site->id)
                    ->whereNull('retried_at')
                    ->count(),
            ],
            'problems' => $problems,
            'notes' => $notes,
        ];
    }

    /**
     * Invoke one real queue slot. This is the only check that proves the
     * DEPLOYED handler is pump-aware — a function deployed before the pump
     * shipped returns plain text instead of the JSON slot report, and the
     * pump then has to guess at queue depth.
     *
     * @return array<string, mixed>
     */
    private function probe(Site $site, ServerlessQueuePump $pump, InvokeFunctionTick $tick): array
    {
        $config = $pump->config($site);

        try {
            $result = $tick->tickQueueSlot($site, [
                'queue' => $config['queue'],
                // Keep the probe short — this is a diagnostic, not a drain.
                'slot_max_time' => 10,
                'slot_max_jobs' => 5,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $report = $result['report'];

        return [
            'ok' => $report['ok'],
            'processed' => $report['processed'],
            'failed' => $report['failed'],
            'remaining' => $report['remaining'],
            // remaining === null means the handler gave no countable depth:
            // either an old handler, or a driver with no size().
            'handler_is_pump_aware' => $report['remaining'] !== null || $report['processed'] > 0,
            'invocation_id' => $result['invocation']?->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(Site $site, array $report): void
    {
        $this->line('');
        $this->line('<options=bold>Queue doctor — '.$site->slug.'</>');
        $this->line('');

        $pump = $report['pump'];
        $this->line('  Queue connection    '.($report['queue_connection'] ?? '<fg=red>not set</>'));
        $this->line('  Deployed            '.($report['deployed'] ? 'yes' : '<fg=red>no</>'));
        $this->line('  Pump enabled        '.($pump['enabled'] ? 'yes' : '<fg=red>no</>'));
        $this->line('  Max concurrency     '.$pump['max_concurrency']);
        $this->line('  Slots running now   '.$pump['active_slots']);
        $this->line('  Failed jobs         '.$report['failed_jobs']['total'].' ('.$report['failed_jobs']['awaiting_retry'].' awaiting retry)');

        if (isset($report['probe'])) {
            $probe = $report['probe'];
            $this->line('');
            $this->line('<options=bold>Live probe</>');

            if (! ($probe['ok'] ?? false) && isset($probe['error'])) {
                $this->line('  <fg=red>Could not reach the function: '.$probe['error'].'</>');
            } else {
                $this->line('  Processed           '.$probe['processed']);
                $this->line('  Failed              '.$probe['failed']);
                $this->line('  Remaining           '.($probe['remaining'] ?? 'unknown (driver could not count)'));
                $this->line('  Pump-aware handler  '.(($probe['handler_is_pump_aware'] ?? false)
                    ? 'yes'
                    : '<fg=yellow>unconfirmed — redeploy if this function predates the queue pump</>'));
            }
        }

        foreach ($report['notes'] as $note) {
            $this->line('');
            $this->line('  <fg=yellow>note</> '.$note);
        }

        foreach ($report['problems'] as $problem) {
            $this->line('');
            $this->line('  <fg=red>problem</> '.$problem);
        }

        $this->line('');

        if ($report['problems'] === []) {
            $this->info('No blocking problems found.');

            return;
        }

        $this->warn(count($report['problems']).' problem(s) found.');
    }

    private function resolveSite(string $needle): ?Site
    {
        return Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
