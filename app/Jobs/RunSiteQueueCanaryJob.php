<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prove a site can actually process a queued job, end to end.
 *
 * Every weaker check passes while jobs never run. A worker reports RUNNING
 * against `QUEUE_CONNECTION=sync` and consumes nothing; a depth of zero looks
 * identical whether the queue is being drained or ignored; a fresh env is
 * invisible to an app with a warm config cache. The only check that covers the
 * whole chain — driver, connection, credentials, a live worker, and the config
 * the app actually booted with — is to put a job in and watch it come out.
 *
 * Same shape as {@see SendBindingTestEmailJob}: a small script in the app's own
 * context, over SSH, reporting into the page-top console.
 */
class RunSiteQueueCanaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /** How long to wait for a worker to pick the job up. */
    private const WAIT_SECONDS = 25;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
        public string $queueName = 'default',
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $emit = new ConsoleEmitter($this->consoleActionId);
        $site = Site::query()->with('server')->find($this->siteId);

        if ($site === null || $site->server === null) {
            $emit->error(__('This site has no server to test against.'), 'canary');
            $this->finish(false, 'No server.');

            return;
        }

        $dir = rtrim((string) $site->effectiveEnvDirectory(), '/');
        $token = (string) Str::ulid();

        $emit->step('canary', __('Dispatching a test job onto :queue …', ['queue' => $this->queueName]));

        $payload = base64_encode((string) json_encode([
            'queue' => $this->queueName,
            'token' => $token,
            'wait' => self::WAIT_SECONDS,
        ]));

        $bash = $this->buildScript($dir, $payload);

        try {
            $out = $exec->runInlineBash($site->server, 'site:queue-canary', $bash, timeoutSeconds: 90, asRoot: false);
        } catch (\Throwable $e) {
            $emit->error(__('Could not run the test: :msg', ['msg' => Str::limit($e->getMessage(), 300)]), 'canary');
            $this->finish(false, Str::limit($e->getMessage(), 300));

            return;
        }

        $result = $this->extract((string) $out->buffer);

        if ($result === null) {
            $emit->error(__('The site did not report a result. Is the app deployed and bootable?'), 'canary');
            $this->finish(false, 'No result returned.');

            return;
        }

        if (($result['error'] ?? null) !== null) {
            $emit->error((string) $result['error'], 'canary');
            $this->finish(false, (string) $result['error']);

            return;
        }

        if (! ($result['consumed'] ?? false)) {
            // The job stays queued. It is a closure that writes one cache key,
            // so a worker starting later runs it harmlessly — worth saying,
            // because "we left something on your queue" should never be a
            // surprise.
            $emit->error(__('No worker picked the job up within :n seconds. It is still queued and harmless — it writes one cache key when something drains it.', ['n' => self::WAIT_SECONDS]), 'canary');
            $emit->step('canary', __('App reports connection :c, driver :d.', [
                'c' => (string) ($result['connection'] ?? '?'),
                'd' => (string) ($result['driver'] ?? 'unknown'),
            ]));
            $this->finish(false, 'Not consumed within '.self::WAIT_SECONDS.'s.');

            return;
        }

        $emit->success(__('Round trip in :ms ms — this site processes queued jobs.', ['ms' => (int) ($result['ms'] ?? 0)]), 'canary');
        $emit->step('canary', __('Connection :c · driver :d · queue :q', [
            'c' => (string) ($result['connection'] ?? '?'),
            'd' => (string) ($result['driver'] ?? '?'),
            'q' => $this->queueName,
        ]));

        // Record what the APP said about itself. dply's env copy can be stale —
        // this run proved redis while the stored .env still read sync, and a
        // readiness panel contradicting a green round trip is worse than no
        // panel. An observation beats a record.
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['queue_observed'] = [
            'connection' => (string) ($result['connection'] ?? ''),
            'driver' => (string) ($result['driver'] ?? ''),
            'observed_at' => now()->toIso8601String(),
        ];
        $site->forceFill(['meta' => $meta])->save();

        $this->finish(true, null);
    }

    /**
     * Close the console run.
     *
     * Without this the row stays "running" until the stuck-action reaper marks
     * it failed — which is how a canary reporting a 2,527 ms round trip
     * displayed as "queue worker did not pick this up". The banner was telling
     * the truth about the ROW, not about the queue.
     */
    private function finish(bool $ok, ?string $error): void
    {
        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => $ok ? ConsoleAction::STATUS_COMPLETED : ConsoleAction::STATUS_FAILED,
            'finished_at' => now(),
            'error' => $ok ? null : $error,
            'updated_at' => now(),
        ]);
    }

    /**
     * The bash that writes, runs and removes the canary script.
     *
     * A real file on disk, never `php -r "eval(...)"`. SerializableClosure
     * serialises a closure by READING ITS SOURCE FILE through reflection, so
     * code that arrived via eval() has no file and the dispatch dies with
     * "file_get_contents(Command line code(1) : eval()'d code)". Every other
     * remote reader in dply can use eval because none of them serialises a
     * closure; this one cannot, and the difference is invisible until a real
     * app tries to dispatch.
     */
    private function buildScript(string $dir, string $payload): string
    {
        $scriptPath = '/tmp/dply-canary-'.$this->consoleActionId.'.php';

        return implode("\n", [
            sprintf('cd %s 2>/dev/null || exit 0', escapeshellarg($dir)),
            sprintf("cat > %s <<'DPLYCANARY'", escapeshellarg($scriptPath)),
            "<?php\n".$this->remotePhp(),
            'DPLYCANARY',
            sprintf('DPLY_QC_IN=%s php -d error_reporting=0 %s 2>&1 || true', escapeshellarg($payload), escapeshellarg($scriptPath)),
            // Best effort: a leftover script is inert, but /tmp is not a bin.
            sprintf('rm -f %s || true', escapeshellarg($scriptPath)),
        ]);
    }

    /**
     * Dispatch a closure and wait for it to land.
     *
     * A closure rather than a job class, because dply cannot assume any class
     * exists in the customer's app. It writes a cache key; the loop polls for
     * it. The cache driver has to be shared between processes for that to mean
     * anything, so `array` is detected and refused rather than reported as a
     * failure the operator would go and debug.
     */
    private function remotePhp(): string
    {
        return <<<'PHP'
$in = json_decode(base64_decode((string) getenv('DPLY_QC_IN')), true);
if (! is_array($in)) { return; }
$T = function ($cb, $d = null) { try { return $cb(); } catch (\Throwable $e) { return $d; } };
$app = $T(function () {
    require getcwd().'/vendor/autoload.php';
    $a = require getcwd().'/bootstrap/app.php';
    $a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    return $a;
});
if ($app === null) { echo 'DPLY_QC_START'.json_encode(['error' => 'Could not boot the application.']).'DPLY_QC_END'; return; }
// Pin the connection once and dispatch onto it explicitly. Reading the default
// twice invites the canary testing one connection while the worker drains
// another — the exact mismatch this is supposed to catch.
$conn = $T(fn () => (string) config('queue.default'), '');
$out = ['driver' => $T(fn () => config('queue.connections.'.$conn.'.driver'), null), 'consumed' => false];
$cacheDriver = $T(fn () => config('cache.default'), null);
$store = $T(fn () => config('cache.stores.'.$cacheDriver.'.driver'), null);
if (in_array($store, ['array', 'null'], true)) {
    $out['error'] = 'The app caches to "'.$store.'", which is per-process, so a worker cannot report back through it. Point CACHE_STORE at redis, database or file and try again.';
    echo 'DPLY_QC_START'.json_encode($out).'DPLY_QC_END'; return;
}
if ($out['driver'] === 'sync') {
    $out['error'] = 'QUEUE_CONNECTION is sync: jobs run inline at dispatch and never reach a worker.';
    echo 'DPLY_QC_START'.json_encode($out).'DPLY_QC_END'; return;
}
$key = 'dply:queue-canary:'.$in['token'];
$started = microtime(true);
// Report what actually went wrong. A swallowed exception here made a missing
// class, a serialization failure and a broken connection all read as the same
// guess, which is worse than no message at all.
$out['connection'] = $conn;
try {
    // create() wraps the closure in whatever SerializableClosure the installed
    // Laravel uses; naming that class here would break on a version bump.
    \Illuminate\Support\Facades\Bus::dispatch(
        \Illuminate\Queue\CallQueuedClosure::create(function () use ($key) {
            \Illuminate\Support\Facades\Cache::put($key, microtime(true), 300);
        })->onConnection($conn)->onQueue($in['queue'])
    );
} catch (\Throwable $e) {
    $out['error'] = 'Dispatch failed: '.get_class($e).': '.mb_substr($e->getMessage(), 0, 400);
    echo 'DPLY_QC_START'.json_encode($out).'DPLY_QC_END'; return;
}
$deadline = time() + (int) $in['wait'];
while (time() < $deadline) {
    if ($T(fn () => \Illuminate\Support\Facades\Cache::has($key), false)) {
        $out['consumed'] = true;
        $out['ms'] = (int) round((microtime(true) - $started) * 1000);
        $T(fn () => \Illuminate\Support\Facades\Cache::forget($key));
        break;
    }
    usleep(500000);
}
echo 'DPLY_QC_START'.json_encode($out).'DPLY_QC_END';
PHP;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extract(string $buffer): ?array
    {
        if (preg_match('/DPLY_QC_START(.*?)DPLY_QC_END/s', $buffer, $m) !== 1) {
            return null;
        }

        $data = json_decode(trim($m[1]), true);

        return is_array($data) ? $data : null;
    }
}
