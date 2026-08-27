<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        $bash = sprintf(
            'cd %s 2>/dev/null && DPLY_QC_IN=%s php -d error_reporting=0 -r "eval(base64_decode(\'%s\'));" 2>&1 || true',
            escapeshellarg($dir),
            escapeshellarg($payload),
            base64_encode($this->remotePhp()),
        );

        try {
            $out = $exec->runInlineBash($site->server, 'site:queue-canary', $bash, timeoutSeconds: 90, asRoot: false);
        } catch (\Throwable $e) {
            $emit->error(__('Could not run the test: :msg', ['msg' => Str::limit($e->getMessage(), 300)]), 'canary');

            return;
        }

        $result = $this->extract((string) $out->buffer);

        if ($result === null) {
            $emit->error(__('The site did not report a result. Is the app deployed and bootable?'), 'canary');

            return;
        }

        if (($result['error'] ?? null) !== null) {
            $emit->error((string) $result['error'], 'canary');

            return;
        }

        if (! ($result['consumed'] ?? false)) {
            // The job stays queued. It is a closure that writes one cache key,
            // so a worker starting later runs it harmlessly — worth saying,
            // because "we left something on your queue" should never be a
            // surprise.
            $emit->error(__('No worker picked the job up within :n seconds. It is still queued and harmless — it writes one cache key when something drains it.', ['n' => self::WAIT_SECONDS]), 'canary');
            $emit->step('canary', __('Driver reported by the app: :d', ['d' => (string) ($result['driver'] ?? 'unknown')]));

            return;
        }

        $emit->success(__('Round trip in :ms ms — this site processes queued jobs.', ['ms' => (int) ($result['ms'] ?? 0)]), 'canary');
        $emit->step('canary', __('Driver :d · queue :q', ['d' => (string) ($result['driver'] ?? '?'), 'q' => $this->queueName]));
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
$out = ['driver' => $T(fn () => config('queue.connections.'.config('queue.default').'.driver'), null), 'consumed' => false];
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
$dispatched = $T(function () use ($key, $in) {
    // create() wraps the closure in whatever SerializableClosure the installed
    // Laravel uses; naming that class here would break on a version bump.
    \Illuminate\Support\Facades\Bus::dispatch(
        \Illuminate\Queue\CallQueuedClosure::create(function () use ($key) {
            \Illuminate\Support\Facades\Cache::put($key, microtime(true), 300);
        })->onQueue($in['queue'])
    );
    return true;
}, false);
if (! $dispatched) {
    $out['error'] = 'Could not dispatch a closure onto the queue. This app may have closure serialization disabled.';
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
