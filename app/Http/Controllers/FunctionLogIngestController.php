<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FunctionInvocation;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Receives per-request log records from a deployed DigitalOcean Functions
 * app. The DO activations list API is empty, so organic web traffic is
 * invisible to dply unless the function reports it — the injected handler
 * (resources/serverless/digitalocean-functions-laravel-handler.php) does a
 * fire-and-forget POST here after each request it serves.
 *
 * Each accepted payload becomes one `source=web` FunctionInvocation — the
 * rows behind the Logs page's Visits tab.
 *
 * Authentication is an HMAC-SHA256 over the raw body, keyed by the site's
 * `log_ingest_secret` (injected into the function as DPLY_LOG_INGEST_SECRET
 * at deploy time). No secret on the site, or a bad signature, is a 401.
 */
class FunctionLogIngestController extends Controller
{
    /** Match the DB column ceilings so an oversized field can't error the insert. */
    private const MAX_PATH = 2048;

    private const MAX_LOG_LINES = 200;

    /** Cap any single context string so a crafted header can't bloat a row. */
    private const MAX_CONTEXT_VALUE = 1024;

    /** Per-request detail fields the handler may report, in display order. */
    private const CONTEXT_KEYS = [
        'ip', 'country', 'route', 'query', 'content_type',
        'response_bytes', 'memory_mb', 'php', 'scheme', 'host',
        'referer', 'user_agent',
    ];

    public function __invoke(Request $request, Site $site): JsonResponse
    {
        $secret = trim((string) data_get($site->meta, 'serverless.log_ingest_secret', ''));
        $signature = (string) $request->header('X-Dply-Signature', '');

        if ($secret === '' || $signature === ''
            || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $status = (int) $request->input('status', 0);
        $logLines = $request->input('logs', []);
        $logLines = is_array($logLines)
            ? array_slice(array_values(array_filter($logLines, 'is_string')), -self::MAX_LOG_LINES)
            : [];

        FunctionInvocation::query()->create([
            'site_id' => $site->id,
            'source' => FunctionInvocation::SOURCE_WEB,
            'task' => null,
            'method' => strtoupper(substr((string) $request->input('method', 'GET'), 0, 12)),
            'path' => Str::limit('/'.ltrim((string) $request->input('path', '/'), '/'), self::MAX_PATH, ''),
            'status_code' => $status > 0 ? $status : null,
            'success' => $status > 0 && $status < 400,
            'duration_ms' => max(0, (int) $request->input('duration_ms', 0)),
            'cold' => (bool) $request->boolean('cold'),
            'activation_id' => Str::limit((string) $request->input('activation_id', ''), 64, '') ?: null,
            'log_lines' => $logLines,
            'context' => $this->sanitizeContext($request->input('context', [])),
            'result_excerpt' => null,
            'created_at' => Carbon::now(),
        ]);

        return response()->json(['message' => 'Recorded.'], 202);
    }

    /**
     * Keep only known per-request detail fields, each a bounded scalar — a
     * function handler can't push arbitrary or oversized data into the row.
     *
     * @return array<string, scalar>|null
     */
    private function sanitizeContext(mixed $context): ?array
    {
        if (! is_array($context)) {
            return null;
        }

        $clean = [];
        foreach (self::CONTEXT_KEYS as $key) {
            $value = $context[$key] ?? null;
            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }
            $clean[$key] = is_string($value)
                ? Str::limit($value, self::MAX_CONTEXT_VALUE, '')
                : $value;
        }

        return $clean === [] ? null : $clean;
    }
}
