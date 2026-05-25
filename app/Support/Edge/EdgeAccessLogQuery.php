<?php

declare(strict_types=1);

namespace App\Support\Edge;

use App\Models\EdgeAccessLog;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Shared filter parser for Edge access log queries.
 *
 * Used by both the public API (token-auth JSON+CSV) and the in-product
 * workspace CSV download (session-auth) so the on-screen tail, the JSON
 * API, and the downloaded CSV all interpret ?status / ?method / ?path
 * identically.
 */
final class EdgeAccessLogQuery
{
    public const DEFAULT_LIMIT = 500;

    public const MAX_LIMIT = 5000;

    /**
     * @return Builder<EdgeAccessLog>
     */
    public static function build(Request $request, Site $site, bool $chronological = false): Builder
    {
        $since = self::parseSince($request);
        $limit = self::parseLimit($request);

        $q = EdgeAccessLog::query()
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $since);

        $bucket = strtolower(trim((string) $request->query('status', '')));
        if (in_array($bucket, ['2xx', '3xx', '4xx', '5xx'], true)) {
            $floor = (int) $bucket[0] * 100;
            $q->whereBetween('status_code', [$floor, $floor + 99]);
        }

        $methodsRaw = (string) $request->query('method', '');
        if ($methodsRaw !== '') {
            $methods = collect(explode(',', $methodsRaw))
                ->map(fn ($m): string => strtoupper(trim((string) $m)))
                ->filter(fn (string $m): bool => $m !== '' && preg_match('/^[A-Z]{1,12}$/', $m) === 1)
                ->values()
                ->all();
            if ($methods !== []) {
                $q->whereIn('method', $methods);
            }
        }

        $path = trim((string) $request->query('path', ''));
        if ($path !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $path).'%';
            $q->where('path', 'like', $like);
        }

        return $chronological
            ? $q->orderBy('occurred_at')->limit($limit)
            : $q->orderByDesc('occurred_at')->limit($limit);
    }

    public static function parseSince(Request $request): Carbon
    {
        $raw = trim((string) $request->query('since', '1h'));

        if (preg_match('/^(\d+)([smhd])$/', $raw, $m) === 1) {
            $n = max(1, (int) $m[1]);

            return match ($m[2]) {
                's' => now()->subSeconds($n),
                'm' => now()->subMinutes($n),
                'h' => now()->subHours($n),
                'd' => now()->subDays($n),
            };
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return now()->subHour();
        }
    }

    public static function parseLimit(Request $request): int
    {
        $limit = (int) $request->query('limit', (string) self::DEFAULT_LIMIT);

        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
