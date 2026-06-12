<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\PushWorkerPoolAgentConfigJob;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

/**
 * Box-side real-time agent: forwards Horizon job lifecycle events to dply for the
 * live pool dashboard. Active ONLY when dply configured the box
 * (DPLY_POOL_EVENT_URL + _TOKEN, written by {@see PushWorkerPoolAgentConfigJob});
 * a no-op everywhere else, so registering it globally is safe.
 *
 * Buffer + batch + shed (self-flushing, no extra process):
 *  - each event is appended to a capped local Redis list;
 *  - the buffer flushes in one batched POST when it reaches FLUSH_COUNT, when the
 *    oldest buffered event is older than FLUSH_AGE, or immediately on a FAILED job;
 *  - on overflow the oldest events are dropped and a running "dropped" counter is
 *    sent with the next flush, so the UI can show "+N more" instead of lying;
 *  - a short single-flusher lock prevents concurrent workers double-sending.
 *
 * The feed is a TAIL, not an audit log — dropping under load is acceptable; the
 * snapshot holds the authoritative counts. Never throws into job processing.
 */
class ForwardWorkerPoolJobEvent
{
    private const BUF = 'dply:pool-events:buffer';

    private const FIRST = 'dply:pool-events:first-at';

    private const DROPPED = 'dply:pool-events:dropped';

    private const LOCK = 'dply:pool-events:flush-lock';

    private const MAX_BUFFER = 500;

    private const FLUSH_COUNT = 25;

    private const FLUSH_AGE = 2.0;

    public function handleProcessing(JobProcessing $event): void
    {
        $this->forward($event->job?->resolveName(), $event->job?->getQueue(), 'processing', $event->job?->uuid());
    }

    public function handleProcessed(JobProcessed $event): void
    {
        $this->forward($event->job?->resolveName(), $event->job?->getQueue(), 'completed', $event->job?->uuid());
    }

    public function handleFailed(JobFailed $event): void
    {
        $this->forward($event->job?->resolveName(), $event->job?->getQueue(), 'failed', $event->job?->uuid());
    }

    private function forward(?string $name, ?string $queue, string $status, ?string $uuid): void
    {
        $url = (string) env('DPLY_POOL_EVENT_URL', '');
        $token = (string) env('DPLY_POOL_EVENT_TOKEN', '');
        if ($url === '' || $token === '' || $name === null) {
            return;
        }

        try {
            $redis = Redis::connection();
            $now = microtime(true);

            // Shed: cap the buffer, dropping oldest under sustained overload.
            if ((int) $redis->llen(self::BUF) >= self::MAX_BUFFER) {
                $redis->ltrim(self::BUF, (int) (-self::MAX_BUFFER + 1), -1);
                $redis->incr(self::DROPPED);
            }

            $redis->rpush(self::BUF, json_encode([
                'name' => $name,
                'queue' => $queue ?? 'default',
                'status' => $status,
                'uuid' => $uuid,
                'at' => $now,
            ]));
            $redis->setnx(self::FIRST, (string) $now);

            $len = (int) $redis->llen(self::BUF);
            $first = (float) $redis->get(self::FIRST);
            $stale = $first > 0 && ($now - $first) >= self::FLUSH_AGE;

            if ($status === 'failed' || $len >= self::FLUSH_COUNT || $stale) {
                $this->flush($redis, $url, $token);
            }
        } catch (\Throwable) {
            // Telemetry must never break job processing.
        }
    }

    private function flush(mixed $redis, string $url, string $token): void
    {
        // Single-flusher lock so concurrent workers don't double-send.
        if (! $redis->set(self::LOCK, '1', 'EX', 5, 'NX')) {
            return;
        }

        try {
            // Atomically claim the current buffer so events pushed mid-flush survive.
            $tmp = self::BUF.':'.bin2hex(random_bytes(4));
            try {
                $redis->rename(self::BUF, $tmp);
            } catch (\Throwable) {
                return; // nothing buffered
            }
            $redis->del(self::FIRST);

            $raw = $redis->lrange($tmp, 0, -1);
            $redis->del($tmp);
            if (empty($raw)) {
                return;
            }

            $dropped = (int) $redis->get(self::DROPPED);
            $redis->del(self::DROPPED);

            $payload = ['events' => array_values(array_filter(array_map(
                fn ($e) => json_decode((string) $e, true),
                $raw,
            )))];
            if ($dropped > 0) {
                $payload['dropped'] = $dropped;
            }

            Http::withToken($token)->connectTimeout(1)->timeout(2)->post($url, $payload);
        } catch (\Throwable) {
            // Best-effort.
        } finally {
            $redis->del(self::LOCK);
        }
    }
}
