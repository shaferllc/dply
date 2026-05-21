<?php

declare(strict_types=1);

namespace App\Services\Serverless;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fire a single dply tick against a serverless function and record the
 * result onto the site's `serverless.tick_history` ring buffer.
 *
 * Both the scheduled {@see \App\Console\Commands\ServerlessTickCommand}
 * (every minute, all enabled sites) and the in-UI "Tick now" buttons on
 * the Schedule / Workers pages (one-off, single site) delegate to this
 * service so the history entries are identical regardless of how the
 * tick was triggered.
 */
final class InvokeFunctionTick
{
    /** Ring-buffer size for per-site tick history. */
    public const HISTORY_LIMIT = 50;

    /** Truncate response body previews so site meta stays under control. */
    public const BODY_PREVIEW_BYTES = 1500;

    /**
     * Tick a single task ('schedule' / 'queue' / 'keep-warm') for the site.
     *
     * Returns the history entry that was pushed, or null when the site
     * isn't tickable (no action_url, or command-mode without a webhook
     * secret).
     *
     * @return array<string, mixed>|null
     */
    public function tickSite(Site $site, string $task): ?array
    {
        $url = (string) data_get($site->meta, 'serverless.action_url', '');
        if ($url === '') {
            return null;
        }

        $headers = [];
        if ($task === 'schedule' || $task === 'queue') {
            $secret = trim((string) $site->webhook_secret);
            if ($secret === '') {
                return null;
            }
            $headers = [
                'X-Dply-Run' => $task,
                'X-Dply-Secret' => $secret,
            ];
        }

        $startedAt = Carbon::now();
        $startedMs = (int) round(microtime(true) * 1000);
        $entry = [
            'at' => $startedAt->toIso8601String(),
            'task' => $task,
            'status' => 'failed',
            'http_status' => null,
            'duration_ms' => 0,
            'body_preview' => '',
            'error' => null,
        ];

        try {
            $response = Http::withHeaders($headers)->timeout(70)->get($url);
            $entry['http_status'] = $response->status();
            $entry['status'] = $response->successful() ? 'ok' : 'failed';
            $entry['body_preview'] = mb_strcut((string) $response->body(), 0, self::BODY_PREVIEW_BYTES);
        } catch (Throwable $e) {
            $entry['error'] = $e->getMessage();
            Log::warning('serverless.tick.failed', [
                'site_id' => $site->id,
                'task' => $task,
                'error' => $e->getMessage(),
            ]);
        }

        $entry['duration_ms'] = max(0, (int) round(microtime(true) * 1000) - $startedMs);
        $this->recordHistory($site, $entry);

        return $entry;
    }

    /**
     * Push the tick entry onto the site's bounded history buffer.
     *
     * @param  array<string, mixed>  $entry
     */
    private function recordHistory(Site $site, array $entry): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $history = is_array($serverless['tick_history'] ?? null) ? $serverless['tick_history'] : [];

        $history[] = $entry;
        if (count($history) > self::HISTORY_LIMIT) {
            $history = array_slice($history, -self::HISTORY_LIMIT);
        }

        $serverless['tick_history'] = array_values($history);
        $serverless['last_tick_at'] = $entry['at'];
        $meta['serverless'] = $serverless;

        // saveQuietly so the minute-cadence writes don't churn updated_at —
        // the dashboard's "last edited" timestamp should reflect operator
        // changes, not background-tick writes.
        $site->forceFill(['meta' => $meta])->saveQuietly();
    }
}
