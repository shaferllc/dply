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
 * Read the app's failed jobs — the ones still sitting there, retryable.
 *
 * The depth sweep reports a failure COUNT, which tells an operator something is
 * wrong and nothing about what. This reads the records themselves so the page
 * can name the class, the queue and the exception, and hand each one to
 * `queue:retry` or `queue:forget` ({@see ControlWorkerDaemonJob}).
 *
 * Goes through the app's own failed-job provider rather than a raw `failed_jobs`
 * SELECT: the table name is configurable, the id is a uuid on some drivers and
 * an integer on others, and Laravel already resolves both. It also means a site
 * with `QUEUE_FAILED_DRIVER=null` reports "not stored" instead of an empty list
 * that looks like good news.
 *
 * Cached, never persisted: a failed job is the app's record, not dply's, and it
 * stops existing the moment it is retried.
 */
class CollectSiteFailedJobsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    /** Enough to work through a bad morning; not a data export. */
    public const LIMIT = 50;

    public function __construct(public string $siteId)
    {
        $this->onQueue('dply-control');
    }

    public static function cacheKey(string $siteId): string
    {
        return 'site:'.$siteId.':failed-jobs';
    }

    /**
     * @return array{jobs: list<array<string, mixed>>, total: int, driver: ?string, error: ?string, read_at: ?string}|null
     */
    public static function cached(string $siteId): ?array
    {
        $value = Cache::get(self::cacheKey($siteId));

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

        $payload = base64_encode((string) json_encode(['limit' => self::LIMIT]));
        $php = base64_encode($this->remotePhp());

        $bash = sprintf(
            'cd %s 2>/dev/null && DPLY_FJ_IN=%s php -d error_reporting=0 -r "eval(base64_decode(\'%s\'));" 2>/dev/null || true',
            escapeshellarg($dir),
            escapeshellarg($payload),
            $php,
        );

        $result = null;

        try {
            $out = $exec->runInlineBash($site->server, 'site:failed-jobs', $bash, timeoutSeconds: 60, asRoot: false);
        } catch (\Throwable $e) {
            Log::info('failed jobs: exec failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            $result = ['error' => 'Could not read the application: '.$e->getMessage()];
        }

        $result ??= $this->extract((string) $out->buffer);

        Cache::put(self::cacheKey($this->siteId), [
            'jobs' => array_values((array) ($result['jobs'] ?? [])),
            'total' => (int) ($result['total'] ?? 0),
            'driver' => $result['driver'] ?? null,
            'error' => $result['error'] ?? null,
            'read_at' => now()->toIso8601String(),
        ], now()->addMinutes(3));
    }

    /**
     * Ask the app's failed-job provider, newest first.
     *
     * Only the exception's FIRST LINE travels — class and message. The stack
     * trace below it routinely quotes arguments, so shipping the whole thing to
     * make a list prettier would put customer data in dply's cache: the same
     * trade the in-app agent already refuses.
     */
    private function remotePhp(): string
    {
        return <<<'PHP'
$in = json_decode(base64_decode((string) getenv('DPLY_FJ_IN')), true);
if (! is_array($in)) { return; }
$T = function ($cb, $d = null) { try { return $cb(); } catch (\Throwable $e) { return $d; } };
$app = $T(function () {
    require getcwd().'/vendor/autoload.php';
    $a = require getcwd().'/bootstrap/app.php';
    $a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    return $a;
});
$done = function (array $o) { echo 'DPLY_FJ_START'.json_encode($o).'DPLY_FJ_END'; };
if ($app === null) { $done(['error' => 'Could not boot the application.']); return; }
$driver = $T(fn () => config('queue.failed.driver'), null);
if ($driver === null || $driver === 'null') {
    $done(['driver' => $driver, 'error' => 'This app does not store failed jobs (QUEUE_FAILED_DRIVER is null), so a job that fails is gone. Point it at the database driver to keep them.']);
    return;
}
$failer = $T(fn () => $app->make('queue.failer'), null);
if ($failer === null) { $done(['driver' => $driver, 'error' => 'The app has no failed-job provider configured.']); return; }
$all = $T(fn () => $failer->all(), []);
$rows = [];
$limit = (int) $in['limit'];
foreach ((array) $all as $row) {
    if (count($rows) >= $limit) { break; }
    $payload = $T(fn () => json_decode((string) ($row->payload ?? ''), true), null);
    $exception = (string) ($row->exception ?? '');
    // First line only: class + message. The trace beneath it quotes arguments.
    $first = trim(strtok($exception, "\n") ?: '');
    $rows[] = [
        // database-uuids gives a uuid; the plain database driver an integer.
        // queue:retry accepts either, so pass through whatever this app uses.
        'uuid' => (string) ($row->uuid ?? $row->id ?? ''),
        'name' => (string) ($payload['displayName'] ?? 'job'),
        'queue' => (string) ($row->queue ?? ''),
        'connection' => (string) ($row->connection ?? ''),
        'attempts' => is_numeric($payload['attempts'] ?? null) ? (int) $payload['attempts'] : null,
        'exception' => mb_substr($first, 0, 300),
        'failed_at' => (string) ($row->failed_at ?? ''),
    ];
}
$done(['driver' => $driver, 'total' => is_countable($all) ? count($all) : count($rows), 'jobs' => $rows]);
PHP;
    }

    /**
     * @return array<string, mixed>
     */
    private function extract(string $buffer): array
    {
        if (preg_match('/DPLY_FJ_START(.*?)DPLY_FJ_END/s', $buffer, $m) !== 1) {
            return ['error' => 'The site did not return a readable failed-job list. Is the app deployed and bootable?'];
        }

        $data = json_decode(trim($m[1]), true);

        return is_array($data) ? $data : ['error' => 'The site returned an unreadable failed-job list.'];
    }
}
