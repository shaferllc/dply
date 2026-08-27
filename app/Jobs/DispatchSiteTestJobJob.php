<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteQueueJobRun;
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
 * Put one of the app's OWN jobs on the queue and watch it leave.
 *
 * {@see RunSiteQueueCanaryJob} proves the pipe works using a closure dply
 * wrote. This proves a specific job of yours works, which is a different
 * question: a queue can be perfectly healthy while `SendInvoice` fatals on
 * every attempt.
 *
 * This runs REAL work. Whatever the job does in production, it does now. What
 * bounds it is not the page's confirm but the argument list: dply can pass
 * numbers and strings, so a job wanting an Order stays out of reach — scanning
 * dply's own 252 job classes found not one that takes zero arguments, which is
 * why passing them is supported at all.
 *
 * The run is recorded the moment it is dispatched, as `queued`, and promoted to
 * `taken` if queue depth returns to its pre-dispatch level. Neither means
 * success: a job that FAILED leaves the queue too, and only the in-app agent
 * can close that gap — it reports against the same job id captured here.
 */
class DispatchSiteTestJobJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 150;

    /** How long to watch for a worker to take it. */
    private const WAIT_SECONDS = 30;

    /**
     * @param  list<scalar|null>  $args  Constructor arguments, positionally.
     */
    public function __construct(
        public string $consoleActionId,
        public string $siteId,
        public string $jobClass,
        public string $queueName = 'default',
        public array $args = [],
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $emit = new ConsoleEmitter($this->consoleActionId);
        $site = Site::query()->with('server')->find($this->siteId);

        if ($site === null || $site->server === null) {
            $emit->error(__('This site has no server to dispatch on.'), 'dispatch');
            $this->finish(false, 'No server.');

            return;
        }

        $dir = rtrim((string) $site->effectiveEnvDirectory(), '/');

        $emit->step('dispatch', __('Dispatching :class …', ['class' => class_basename($this->jobClass)]));

        $payload = base64_encode((string) json_encode([
            'class' => $this->jobClass,
            'queue' => $this->queueName,
            'wait' => self::WAIT_SECONDS,
            'args' => $this->args,
        ]));
        $php = base64_encode($this->remotePhp());

        $bash = sprintf(
            'cd %s 2>/dev/null && DPLY_TD_IN=%s php -d error_reporting=0 -r "eval(base64_decode(\'%s\'));" 2>&1 || true',
            escapeshellarg($dir),
            escapeshellarg($payload),
            $php,
        );

        try {
            $out = $exec->runInlineBash($site->server, 'site:queue-dispatch', $bash, timeoutSeconds: 120, asRoot: false);
        } catch (\Throwable $e) {
            $emit->error(__('Could not dispatch: :msg', ['msg' => Str::limit($e->getMessage(), 300)]), 'dispatch');
            $this->finish(false, Str::limit($e->getMessage(), 300));

            return;
        }

        $result = $this->extract((string) $out->buffer);

        if ($result === null) {
            $emit->error(__('The site did not report a result. Is the app deployed and bootable?'), 'dispatch');
            $this->finish(false, 'No result returned.');

            return;
        }

        if (($result['error'] ?? null) !== null) {
            $emit->error((string) $result['error'], 'dispatch');
            $this->finish(false, Str::limit((string) $result['error'], 300));

            return;
        }

        $queue = (string) ($result['queue'] ?? $this->queueName);
        $drained = (bool) ($result['drained'] ?? false);
        $ms = $drained ? (int) ($result['ms'] ?? 0) : null;

        // The row goes in either way. The dispatch SUCCEEDED — the job is on the
        // queue — and a run that leaves no trace because nothing drained it
        // within half a minute is the exact hole that made this page look
        // broken: press Run, watch nothing appear anywhere.
        SiteQueueJobRun::query()->create([
            'site_id' => $site->id,
            'job_id' => (string) ($result['job_id'] ?? '') ?: null,
            'name' => $this->jobClass,
            'queue' => $queue,
            'connection' => (string) ($result['connection'] ?? '') ?: null,
            'status' => $drained ? SiteQueueJobRun::STATUS_TAKEN : SiteQueueJobRun::STATUS_QUEUED,
            'source' => SiteQueueJobRun::SOURCE_MANUAL,
            'duration_ms' => $ms,
            'attempts' => 1,
            'message' => $drained
                ? __('Dispatched from dply; a worker took it off the queue.')
                : __('Dispatched from dply; still waiting for a worker.'),
            'ran_at' => now(),
        ]);

        if (! $drained) {
            // Not an error: the job is queued, which is what Run promised. The
            // history row now carries the state, so the console says what
            // happened rather than failing the run.
            $emit->success(__('Dispatched onto :q. Nothing took it within :n seconds — it stays queued, and History tracks it.', [
                'q' => $queue,
                'n' => self::WAIT_SECONDS,
            ]), 'dispatch');
            $this->finish(true, null);

            return;
        }

        $emit->success(__('A worker took :class off :q after :ms ms.', [
            'class' => class_basename($this->jobClass),
            'q' => $queue,
            'ms' => $ms,
        ]), 'dispatch');
        $emit->step('dispatch', __('Whether it SUCCEEDED is only visible with the queue agent installed — a failed job leaves the queue too.'));

        $this->finish(true, null);
    }

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
     * Construct the job, dispatch it, and watch the depth come back down.
     *
     * Every guard the page applies is re-applied here. The class name crosses a
     * process boundary as a string, and "the button was disabled" is not a
     * safety property — a Mailable, or a job wanting constructor arguments, is
     * refused on the box where it would otherwise fatal mid-dispatch.
     */
    private function remotePhp(): string
    {
        return <<<'PHP'
$in = json_decode(base64_decode((string) getenv('DPLY_TD_IN')), true);
if (! is_array($in)) { return; }
$T = function ($cb, $d = null) { try { return $cb(); } catch (\Throwable $e) { return $d; } };
$app = $T(function () {
    require getcwd().'/vendor/autoload.php';
    $a = require getcwd().'/bootstrap/app.php';
    $a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    return $a;
});
$done = function (array $o) { echo 'DPLY_TD_START'.json_encode($o).'DPLY_TD_END'; };
if ($app === null) { $done(['error' => 'Could not boot the application.']); return; }
$class = (string) $in['class'];
if (! $T(fn () => class_exists($class), false)) { $done(['error' => 'This app has no class '.$class.'. Re-scan — the catalogue may predate a deploy.']); return; }
$ref = $T(fn () => new \ReflectionClass($class), null);
if ($ref === null || ! $ref->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class)) { $done(['error' => $class.' is not a queued job.']); return; }
if (is_subclass_of($class, \Illuminate\Mail\Mailable::class) || is_subclass_of($class, \Illuminate\Notifications\Notification::class)) {
    $done(['error' => $class.' is a mailable or notification: it needs a recipient, so it cannot be dispatched on its own.']); return;
}
if ($ref->implementsInterface(\Illuminate\Contracts\Broadcasting\ShouldBroadcast::class)) {
    $done(['error' => $class.' is a broadcast event. It is queued by event() when the app fires it, not dispatched onto the bus.']); return;
}
$args = array_values((array) ($in['args'] ?? []));
$ctor = $ref->getConstructor();
if ($ctor !== null) {
    if (count($args) < $ctor->getNumberOfRequiredParameters()) {
        $done(['error' => $class.' needs '.$ctor->getNumberOfRequiredParameters().' argument(s); '.count($args).' given.']); return;
    }
    // Cast to the declared type. JSON gives back int/float/string/bool, which
    // covers every builtin; a class-typed parameter is refused rather than
    // guessed, because "an Order" has no scalar spelling.
    foreach ($ctor->getParameters() as $i => $p) {
        if (! array_key_exists($i, $args)) { break; }
        $t = $p->getType();
        if ($t instanceof \ReflectionNamedType && ! $t->isBuiltin()) {
            $done(['error' => '$'.$p->getName().' is a '.$t->getName().'. dply can pass numbers and strings, not model instances.']); return;
        }
        if ($t instanceof \ReflectionNamedType && $args[$i] !== null) {
            if ($t->getName() === 'int') { $args[$i] = (int) $args[$i]; }
            elseif ($t->getName() === 'float') { $args[$i] = (float) $args[$i]; }
            elseif ($t->getName() === 'string') { $args[$i] = (string) $args[$i]; }
            elseif ($t->getName() === 'bool') { $args[$i] = (bool) $args[$i]; }
        }
    }
}
$conn = $T(fn () => (string) config('queue.default'), '');
$driver = $T(fn () => config('queue.connections.'.$conn.'.driver'), null);
if ($driver === 'sync') { $done(['error' => 'QUEUE_CONNECTION is sync: this would run inline in a throwaway CLI process, not on a worker.']); return; }
$job = $T(fn () => $ref->newInstanceArgs($args));
if ($job === null) { $done(['error' => 'Could not construct '.$class.' from the arguments given.']); return; }
// A job that declares its own queue goes there whatever the page asked for —
// dispatching onto one queue and watching another is how a healthy run reads
// as a failure.
$declared = $T(fn () => is_string($job->queue ?? null) && $job->queue !== '' ? $job->queue : null);
$queue = $declared ?? (string) $in['queue'];
$out = ['connection' => $conn, 'driver' => $driver, 'queue' => $queue, 'drained' => false, 'job_id' => null];
$size = function () use ($T, $conn, $queue) { return (int) $T(fn () => \Illuminate\Support\Facades\Queue::connection($conn)->size($queue), -1); };
$before = $size();
$started = microtime(true);
// The id the WORKER will report for this job. JobQueued carries the same id
// the agent later keys processed/failed by, so capturing it here is what lets
// one history row be opened at dispatch and closed by the agent, instead of
// two rows describing one run.
// NOT wrapped in $T: an arrow function captures by VALUE, so the listener would
// bind its reference to a copy and the id would be captured into a variable
// that no longer exists. Event::listen does not throw anyway.
$jobId = null;
\Illuminate\Support\Facades\Event::listen(
    \Illuminate\Queue\Events\JobQueued::class,
    function ($e) use (&$jobId) { $jobId = $jobId ?? (is_scalar($e->id ?? null) ? (string) $e->id : null); }
);
try {
    if (method_exists($job, 'onConnection')) { $job->onConnection($conn); }
    if (method_exists($job, 'onQueue')) { $job->onQueue($queue); }
    \Illuminate\Support\Facades\Bus::dispatch($job);
} catch (\Throwable $e) {
    $out['error'] = 'Dispatch failed: '.get_class($e).': '.mb_substr($e->getMessage(), 0, 400);
    $done($out); return;
}
$out['job_id'] = $jobId;
$deadline = time() + (int) $in['wait'];
while (time() < $deadline) {
    usleep(400000);
    // Depth back to where it started means a worker took ONE job — not
    // necessarily this one on a busy queue. Honest signal, not a receipt.
    if ($size() <= $before) { $out['drained'] = true; $out['ms'] = (int) round((microtime(true) - $started) * 1000); break; }
}
$done($out);
PHP;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extract(string $buffer): ?array
    {
        if (preg_match('/DPLY_TD_START(.*?)DPLY_TD_END/s', $buffer, $m) !== 1) {
            return null;
        }

        $data = json_decode(trim($m[1]), true);

        return is_array($data) ? $data : null;
    }
}
