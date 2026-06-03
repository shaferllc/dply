<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\WorkerPool;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatches N throwaway queued closures onto the pool app's queue and checks
 * that the workers actually process them — a "are the workers working?" probe.
 *
 * App-agnostic: queued closures are core Laravel (CallQueuedClosure), so no
 * app-specific job class is needed. Each closure logs a unique marker; after a
 * short wait we grep the app log for the marker and read the queue size before
 * and after. Streamed to the pool primary's `worker_pool_test` console.
 */
class RunWorkerPoolTestJobsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 120;

    private ?Server $resolvedSubject = null;

    public function __construct(
        public string $poolId,
        public int $count = 5,
        public ?string $userId = null,
    ) {
        $this->onQueue('dply-control');
    }

    protected function consoleSubject(): Model
    {
        if ($this->resolvedSubject instanceof Server) {
            return $this->resolvedSubject;
        }
        $pool = WorkerPool::query()->with('servers')->find($this->poolId);
        $server = $pool?->primaryServer ?? $pool?->sourceServer;

        return $this->resolvedSubject = ($server instanceof Server ? $server : new Server);
    }

    protected function consoleKind(): string
    {
        return 'worker_pool_test';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $pool = WorkerPool::query()->with('servers')->find($this->poolId);
        if (! $pool instanceof WorkerPool) {
            return;
        }
        $primary = $pool->primaryServer ?? $pool->sourceServer;
        if (! $primary instanceof Server) {
            return;
        }
        $this->resolvedSubject = $primary;

        $site = $this->appSite($primary);
        if (! $site instanceof Site) {
            return;
        }
        $dir = rtrim($site->effectiveEnvDirectory(), '/');
        $n = max(1, min(50, $this->count));
        // Marker varies per run; Str::random keeps it deterministic-free.
        $marker = 'DPLY_POOL_TEST_'.bin2hex(random_bytes(4));

        $emit = $this->beginConsoleAction();

        try {
            $emit->step('test', sprintf('dispatching %d test job(s) onto the queue from %s', $n, $dir));

            $out = $exec->runInlineBash($primary, 'worker-pool:test-jobs', $this->script($dir, $marker, $n), timeoutSeconds: 90, asRoot: false);
            foreach (preg_split('/\r?\n/', (string) $out->buffer) ?: [] as $line) {
                if (trim($line) !== '') {
                    $level = str_contains($line, 'RESULT: OK') ? 'success'
                        : (str_contains($line, 'RESULT:') ? 'warn' : 'info');
                    $emit($line, $level, 'test');
                }
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error('Test run failed: '.$e->getMessage(), 'test');
            $this->failConsoleAction($e->getMessage());

            throw $e;
        }
    }

    private function appSite(Server $member): ?Site
    {
        $sites = $member->sites()->get();

        return $sites->first(fn (Site $s): bool => $s->isLaravelFrameworkDetected()) ?? $sites->first();
    }

    private function script(string $dir, string $marker, int $n): string
    {
        $dirArg = escapeshellarg($dir);

        // Dispatch the closures from a REAL bootstrapped PHP file (not tinker).
        // A closure defined in eval'd/tinker code has no source file, so
        // serializable-closure can't reconstruct it on the worker ("bindTo() on
        // null"). A closure in a real file serializes its source fine.
        $phpTemplate = <<<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
for ($i = 1; $i <= __N__; $i++) {
    dispatch(function () use ($i) { \Illuminate\Support\Facades\Log::info('__MARKER__ #'.$i); });
}
fwrite(STDOUT, "dispatched __N__\n");
PHP;
        $php = str_replace(['__N__', '__MARKER__'], [(string) $n, $marker], $phpTemplate);
        $b64Arg = escapeshellarg(base64_encode($php));

        return <<<BASH
DIR={$dirArg}
MARKER={$marker}
N={$n}
cd "\$DIR" 2>/dev/null || { echo "no app dir: \$DIR"; exit 0; }
TESTFILE="dply-test-\$MARKER.php"
BEFORE=\$(php artisan queue:size 2>/dev/null | grep -oE '[0-9]+' | tail -n1 || echo "?")
echo "queue size before: \$BEFORE"
printf '%s' {$b64Arg} | base64 -d > "\$TESTFILE"
php "\$TESTFILE" 2>&1 | tail -n5 || echo "dispatch step reported an error"
rm -f "\$TESTFILE"
echo "dispatched \$N closure job(s) — marker \$MARKER"
echo "waiting for workers to process…"
sleep 7
RAN=\$(grep -c "\$MARKER" storage/logs/laravel.log 2>/dev/null || echo 0)
AFTER=\$(php artisan queue:size 2>/dev/null | grep -oE '[0-9]+' | tail -n1 || echo "?")
echo "queue size after: \$AFTER"
echo "processed (log markers found): \$RAN / \$N"
if [ "\$RAN" -ge "\$N" ] 2>/dev/null; then echo "RESULT: OK — workers processed the test jobs"; else echo "RESULT: not all jobs processed yet — worker down/slow, or a different worker grabbed them (see note on shared queue)"; fi
BASH;
    }
}
