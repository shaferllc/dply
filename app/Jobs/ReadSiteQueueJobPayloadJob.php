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

/**
 * Fetch ONE job's full payload, because someone asked to see it.
 *
 * {@see CollectSiteQueueJobsJob} strips `data.command` from every job it lists:
 * that field is the customer's serialized object graph, and the list is cached,
 * so keeping it would leave their data at rest in dply to render a class name.
 *
 * Debugging a stuck job needs that field, though — which argument is wrong is
 * the whole question. So it travels on an explicit click, for one job, to one
 * operator who can already change this site.
 *
 * It is NOT free of storage: dply reads over SSH from a queued job, so the
 * result has to land somewhere the next render can read it. That is a cache
 * entry keyed to the asking user and gone in sixty seconds — never written to
 * the database, never written to the log.
 */
class ReadSiteQueueJobPayloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    /** Long enough to render once, short enough not to be storage. */
    private const TTL_SECONDS = 60;

    /** A payload is a job's arguments, not a file. */
    private const MAX_CHARS = 20000;

    public function __construct(
        public string $siteId,
        public string $queueName,
        public string $jobUuid,
        public string $userId,
        public string $scope = 'waiting',
    ) {
        $this->onQueue('dply-control');
    }

    public static function cacheKey(string $siteId, string $userId, string $uuid): string
    {
        return 'site:'.$siteId.':job-payload:'.$userId.':'.sha1($uuid);
    }

    /**
     * @return array{payload: ?string, error: ?string}|null
     */
    public static function cached(string $siteId, string $userId, string $uuid): ?array
    {
        $value = Cache::get(self::cacheKey($siteId, $userId, $uuid));

        return is_array($value) ? $value : null;
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
            'uuid' => $this->jobUuid,
            'scope' => $this->scope === 'delayed' ? 'delayed' : 'waiting',
            'max' => self::MAX_CHARS,
        ]));
        $php = base64_encode($this->remotePhp());

        $bash = sprintf(
            'cd %s 2>/dev/null && DPLY_JP_IN=%s php -d error_reporting=0 -r "eval(base64_decode(\'%s\'));" 2>/dev/null || true',
            escapeshellarg($dir),
            escapeshellarg($payload),
            $php,
        );

        $result = null;

        try {
            $out = $exec->runInlineBash($site->server, 'site:job-payload', $bash, timeoutSeconds: 45, asRoot: false);
        } catch (\Throwable $e) {
            // The message, not the trace: this lands in a cache dply renders.
            $result = ['error' => 'Could not read the job: '.$e->getMessage()];
        }

        $result ??= $this->extract((string) $out->buffer);

        Cache::put(
            self::cacheKey($this->siteId, $this->userId, $this->jobUuid),
            ['payload' => $result['payload'] ?? null, 'error' => $result['error'] ?? null],
            now()->addSeconds(self::TTL_SECONDS),
        );
    }

    /**
     * Find the job by its envelope uuid and return the whole envelope.
     *
     * Scanning rather than indexing: neither store indexes by uuid, and the
     * list this is launched from is capped at fifty, so the scan is bounded by
     * roughly the window the operator is already looking at.
     */
    private function remotePhp(): string
    {
        return <<<'PHP'
$in = json_decode(base64_decode((string) getenv('DPLY_JP_IN')), true);
if (! is_array($in)) { return; }
$T = function ($cb, $d = null) { try { return $cb(); } catch (\Throwable $e) { return $d; } };
$app = $T(function () {
    require getcwd().'/vendor/autoload.php';
    $a = require getcwd().'/bootstrap/app.php';
    $a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    return $a;
});
$done = function (array $o) { echo 'DPLY_JP_START'.json_encode($o).'DPLY_JP_END'; };
if ($app === null) { $done(['error' => 'Could not boot the application.']); return; }
$queue = (string) $in['queue'];
$uuid = (string) $in['uuid'];
$delayed = ($in['scope'] ?? 'waiting') === 'delayed';
$conn = $T(fn () => config('queue.default'), null);
$driver = $T(fn () => config('queue.connections.'.$conn.'.driver'), null);
$rows = [];
if ($driver === 'database') {
    $table = $T(fn () => config('queue.connections.'.$conn.'.table', 'jobs'), 'jobs');
    $rows = $T(fn () => \Illuminate\Support\Facades\DB::table($table)->where('queue', $queue)->limit(200)->pluck('payload')->all(), []);
} elseif ($driver === 'redis') {
    $rc = $T(fn () => config('queue.connections.'.$conn.'.connection', 'default'), 'default');
    $redis = $T(fn () => \Illuminate\Support\Facades\Redis::connection($rc), null);
    if ($redis !== null) {
        $rows = $delayed
            ? $T(fn () => $redis->zrange('queues:'.$queue.':delayed', 0, 200), [])
            : $T(fn () => $redis->lrange('queues:'.$queue, 0, 200), []);
    }
} else {
    $done(['error' => 'This driver ('.($driver ?: 'unknown').') cannot be read job by job.']); return;
}
foreach ((array) $rows as $row) {
    $data = json_decode((string) $row, true);
    if (! is_array($data) || (string) ($data['uuid'] ?? '') !== $uuid) { continue; }
    // Pretty-printed because it is being read by a person, not parsed.
    $pretty = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $done(['payload' => mb_substr($pretty, 0, (int) $in['max'])]); return;
}
$done(['error' => 'That job is no longer on the queue — it ran, or it moved.']);
PHP;
    }

    /**
     * @return array<string, mixed>
     */
    private function extract(string $buffer): array
    {
        if (preg_match('/DPLY_JP_START(.*?)DPLY_JP_END/s', $buffer, $m) !== 1) {
            return ['error' => 'The site did not return a readable payload.'];
        }

        $data = json_decode(trim($m[1]), true);

        return is_array($data) ? $data : ['error' => 'The site returned an unreadable payload.'];
    }
}
