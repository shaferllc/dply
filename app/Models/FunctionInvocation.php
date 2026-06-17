<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * One recorded invocation of a serverless (DigitalOcean Functions) site.
 *
 * The DO activations list API never returns anything, so this table — not
 * that API — is dply's source of truth for "what has hit this function."
 * Rows arrive two ways: dply's own authenticated blocking invokes (ticks +
 * the Logs-page test button) capture the activation inline; organic web
 * traffic is POSTed in by the deployed function handler.
 */
class FunctionInvocation extends Model
{
    use HasUlids;

    /** Only created_at is tracked — a row is written once, never updated. */
    public const UPDATED_AT = null;

    /** dply invoked the function on a background tick (scheduler/queue/keep-warm). */
    public const SOURCE_TICK = 'tick';

    /** An operator invoked the function from the Logs-page test button. */
    public const SOURCE_TEST = 'test';

    /** Organic HTTP traffic, reported by the function handler's ingest POST. */
    public const SOURCE_WEB = 'web';

    protected $fillable = [
        'site_id',
        'function_action_id',
        'source',
        'task',
        'method',
        'path',
        'status_code',
        'success',
        'duration_ms',
        'cold',
        'activation_id',
        'log_lines',
        'context',
        'result_excerpt',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'cold' => 'boolean',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'log_lines' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo {
        return $this->belongsTo(Site::class);
    }

    /** The action this invocation hit — null for rows not yet backfilled. *
 * @return BelongsTo<FunctionAction, $this>
 */
    public function functionAction(): BelongsTo {
        return $this->belongsTo(FunctionAction::class);
    }

    /** dply-initiated invocations — what the Activations tab shows. */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereIn('source', [self::SOURCE_TICK, self::SOURCE_TEST]);
    }

    /** Organic web traffic — what the Visits tab shows. */
    public function scopeOrganic(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_WEB);
    }

    /** Normalised log lines, always a list of strings. */
    public function logLines(): array
    {
        return array_values(array_filter(
            is_array($this->log_lines) ? $this->log_lines : [],
            'is_string',
        ));
    }

    /**
     * Curated label → value pairs from the request context, for display on
     * the Visits tab. Only non-empty values, in a stable order.
     *
     * @return array<string, string>
     */
    public function contextPairs(): array
    {
        $ctx = is_array($this->context) ? $this->context : [];

        $labels = [
            'ip' => 'Client IP',
            'country' => 'Country',
            'route' => 'Route',
            'query' => 'Query string',
            'response_bytes' => 'Response size',
            'memory_mb' => 'Peak memory',
            'content_type' => 'Content type',
            'php' => 'PHP',
            'referer' => 'Referer',
            'user_agent' => 'User agent',
        ];

        $pairs = [];
        foreach ($labels as $key => $label) {
            $value = $ctx[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $pairs[$label] = match ($key) {
                'response_bytes' => $this->humanBytes((int) $value),
                'memory_mb' => rtrim(rtrim((string) $value, '0'), '.').' MB',
                default => (string) $value,
            };
        }

        return $pairs;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    }

    /**
     * The legacy tick-history array shape the Schedule / Workers pages
     * consume. Kept so those views render unchanged now that the
     * `meta.serverless.tick_history` ring buffer is retired.
     *
     * A transport failure (no activation) maps its message to `error`; a
     * real activation maps its captured body to `body_preview`.
     *
     * @return array{at: ?string, task: ?string, status: string, http_status: ?int, duration_ms: int, body_preview: string, error: ?string}
     */
    public function toTickEntry(): array
    {
        $transportFailure = $this->activation_id === null && ! $this->success;

        return [
            'at' => $this->created_at?->toIso8601String(),
            'task' => $this->task,
            'status' => $this->success ? 'ok' : 'failed',
            'http_status' => $this->status_code,
            'duration_ms' => $this->duration_ms,
            'body_preview' => $transportFailure ? '' : (string) $this->result_excerpt,
            'error' => $transportFailure ? (string) $this->result_excerpt : null,
        ];
    }
}
