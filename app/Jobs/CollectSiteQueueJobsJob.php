<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Read the jobs waiting — or scheduled — on one of a site's queues.
 *
 * Pending jobs are transient by nature, so nothing here is persisted: the
 * result lands in the cache for a couple of minutes and the page renders that.
 * Storing them would make dply's database a function of the customer's
 * throughput, which is a bill that grows without a ceiling.
 *
 * Reads the store, not the app's events — which is why it works on every
 * existing site with no package to install. The cost is that it can only see
 * what is still waiting: a job that already ran left no row behind.
 */
class CollectSiteQueueJobsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    /** Enough to see a pattern; not so many that a 200k backlog is serialised into a cache entry. */
    public const LIMIT = 50;

    public function __construct(
        public string $siteId,
        // NOT $queue: Queueable already defines that property for the queue the
        // JOB runs on, and redeclaring it is a fatal composition error.
        public string $queueName,
        /** 'waiting' for the ready backlog, 'delayed' for jobs scheduled ahead. */
        public string $scope = 'waiting',
    ) {
        $this->onQueue('dply-control');
    }

    public static function cacheKey(string $siteId, string $queue, string $scope = 'waiting'): string
    {
        return 'site:'.$siteId.':queue-jobs:'.$scope.':'.sha1($queue);
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $site = Site::query()->with('server')->find($this->siteId);

        if ($site === null || $site->server === null || ! $site->server->isReady()) {
            return;
        }

        $dir = rtrim((string) $site->effectiveEnvDirectory(), '/');

        if ($dir === '') {
            return;
        }

        $payload = base64_encode((string) json_encode([
            'queue' => $this->queueName,
            'limit' => self::LIMIT,
            'scope' => $this->scope === 'delayed' ? 'delayed' : 'waiting',
        ]));
        $php = base64_encode($this->remotePhp());

        $bash = sprintf(
            'cd %s 2>/dev/null && DPLY_QJ_IN=%s php -d error_reporting=0 -r "eval(base64_decode(\'%s\'));" 2>/dev/null || true',
            escapeshellarg($dir),
            escapeshellarg($payload),
            $php,
        );

        try {
            $out = $exec->runInlineBash($site->server, 'site:queue-jobs', $bash, timeoutSeconds: 60, asRoot: false);
        } catch (\Throwable $e) {
            Log::info('queue jobs: exec failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return;
        }

        $result = $this->extract((string) $out->buffer);

        // Cache even an empty/failed read: the page needs to stop saying
        // "loading" whether or not the box had anything to say.
        Cache::put(self::cacheKey($this->siteId, $this->queueName, $this->scope), [
            'jobs' => $result['jobs'] ?? [],
            'driver' => $result['driver'] ?? null,
            'truncated' => (bool) ($result['truncated'] ?? false),
            'error' => $result['error'] ?? null,
            'read_at' => now()->toIso8601String(),
        ], now()->addMinutes(3));
    }

    /**
     * Boots the app so the queue config resolves, then reads the store for the
     * two drivers whose waiting jobs are readable without instrumentation.
     *
     * SQS and Beanstalkd are deliberately absent: neither exposes a peek at the
     * backlog, so claiming to list them would mean showing an empty list for a
     * queue that is full.
     */
    private function remotePhp(): string
    {
        return <<<'PHP'
$in = json_decode(base64_decode((string) getenv('DPLY_QJ_IN')), true);
if (! is_array($in)) { return; }
$T = function ($cb, $d = null) { try { return $cb(); } catch (\Throwable $e) { return $d; } };
$app = $T(function () {
    require getcwd().'/vendor/autoload.php';
    $a = require getcwd().'/bootstrap/app.php';
    $a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    return $a;
});
if ($app === null) { return; }
$queue = (string) $in['queue'];
$limit = (int) $in['limit'];
$delayed = ($in['scope'] ?? 'waiting') === 'delayed';
$conn = $T(fn () => config('queue.default'), null);
$driver = $T(fn () => config('queue.connections.'.$conn.'.driver'), null);
$now = time();
$out = ['driver' => $driver, 'jobs' => [], 'truncated' => false, 'now' => $now];
// The serialized job never leaves the box. `data.command` is the customer's
// object graph — ids, and on occasion whole models — and this list is CACHED,
// so shipping it would put their data at rest in dply to render a class name.
// The payload viewer fetches one job on request instead.
$strip = function (string $json) {
    $d = json_decode($json, true);
    if (! is_array($d)) { return $json; }
    unset($d['data']['command']);
    return (string) json_encode($d);
};
if ($driver === 'database') {
    $table = $T(fn () => config('queue.connections.'.$conn.'.table', 'jobs'), 'jobs');
    $rows = $T(function () use ($table, $queue, $limit, $delayed, $now) {
        $q = \Illuminate\Support\Facades\DB::table($table)->where('queue', $queue);
        // available_at is when a worker may TAKE the job; ahead of now means
        // scheduled, at or behind means it is simply waiting its turn.
        $q = $delayed ? $q->where('available_at', '>', $now) : $q->where('available_at', '<=', $now);
        return $q->orderBy($delayed ? 'available_at' : 'id')->limit($limit + 1)->get(['payload', 'available_at'])->all();
    }, []);
    $out['truncated'] = count($rows) > $limit;
    foreach (array_slice($rows, 0, $limit) as $row) {
        $out['jobs'][] = [
            'payload' => $strip((string) $row->payload),
            'available_in' => $delayed ? max(0, (int) $row->available_at - $now) : null,
        ];
    }
} elseif ($driver === 'redis') {
    $rc = $T(fn () => config('queue.connections.'.$conn.'.connection', 'default'), 'default');
    $redis = $T(fn () => \Illuminate\Support\Facades\Redis::connection($rc), null);
    if ($redis === null) {
        $out['error'] = 'Could not reach Redis.';
    } elseif ($delayed) {
        // Delayed jobs live in a sorted set scored by their release time; the
        // ready list holds none of them, which is why a scheduled job is
        // invisible in the waiting view.
        $rows = $T(fn () => $redis->zrange('queues:'.$queue.':delayed', 0, $limit, ['withscores' => true]), []);
        $rows = is_array($rows) ? $rows : [];
        $out['truncated'] = count($rows) > $limit;
        $i = 0;
        foreach ($rows as $member => $score) {
            if ($i++ >= $limit) { break; }
            $out['jobs'][] = ['payload' => $strip((string) $member), 'available_in' => max(0, (int) $score - $now)];
        }
    } else {
        $rows = $T(fn () => $redis->lrange('queues:'.$queue, 0, $limit), []);
        $out['truncated'] = count($rows) > $limit;
        foreach (array_slice($rows, 0, $limit) as $row) {
            $out['jobs'][] = ['payload' => $strip((string) $row), 'available_in' => null];
        }
    }
} else {
    $out['error'] = 'This driver ('.($driver ?: 'unknown').') cannot list waiting jobs.';
}
echo 'DPLY_QJ_START'.json_encode($out)."DPLY_QJ_END\n";
PHP;
    }

    /**
     * @return array<string, mixed>
     */
    private function extract(string $buffer): array
    {
        if (preg_match('/DPLY_QJ_START(.*?)DPLY_QJ_END/s', $buffer, $m) !== 1) {
            return ['error' => 'The site did not return a readable job list.'];
        }

        $data = json_decode(trim($m[1]), true);

        return is_array($data) ? $data : ['error' => 'The site returned an unreadable job list.'];
    }
}
