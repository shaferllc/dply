<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Support;

use App\Modules\Serverless\Models\FunctionInvocation;
use Illuminate\Support\Str;

/**
 * One activation record, parsed into the fields dply stores.
 *
 * The same record shape arrives two ways — inline from a blocking invoke, or
 * fetched by the async poller — so the parsing lives here rather than being
 * written twice and drifting.
 *
 * The interesting numbers are annotations rather than top-level fields:
 * `initTime` exists only on a cold start, and `waitTime` is how long the
 * activation queued before a container picked it up.
 *
 * @see https://docs.digitalocean.com/products/functions/reference/activation-records/
 */
final class ActivationRecord
{
    /** Cap the stored result excerpt so a large HTML body can't bloat a row. */
    public const RESULT_EXCERPT_BYTES = 2000;

    /**
     * @param  list<string>  $logLines
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly ?string $activationId,
        public readonly int $durationMs,
        public readonly ?int $waitTimeMs,
        public readonly ?int $initTimeMs,
        public readonly bool $cold,
        public readonly ?int $statusCode,
        public readonly bool $success,
        public readonly array $logLines,
        public readonly ?string $resultExcerpt,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $activation
     */
    public static function fromArray(array $activation): self
    {
        $annotations = self::annotations($activation);

        $initTime = isset($annotations['initTime']) ? (int) $annotations['initTime'] : null;
        $waitTime = isset($annotations['waitTime']) ? (int) $annotations['waitTime'] : null;

        $result = data_get($activation, 'response.result');
        $success = (bool) data_get($activation, 'response.success', false);

        // The handler returns {statusCode, headers, body}; prefer that as the
        // HTTP status, falling back to OpenWhisk's own success/error split.
        $statusCode = (int) (data_get($activation, 'response.result.statusCode')
            ?? ($success ? 200 : 500));

        $activationId = trim((string) ($activation['activationId'] ?? ''));

        // Logs are stored in their own column; keeping them in the record too
        // would double the row for no gain.
        $raw = $activation;
        unset($raw['logs']);

        return new self(
            activationId: $activationId !== '' ? $activationId : null,
            durationMs: (int) ($activation['duration'] ?? 0),
            waitTimeMs: ($waitTime !== null && $waitTime >= 0) ? $waitTime : null,
            initTimeMs: ($initTime !== null && $initTime > 0) ? $initTime : null,
            cold: $initTime !== null && $initTime > 0,
            statusCode: $statusCode > 0 ? $statusCode : null,
            success: $success,
            logLines: array_values(array_filter((array) data_get($activation, 'logs', []), 'is_string')),
            resultExcerpt: self::excerpt($result),
            raw: $raw,
        );
    }

    /**
     * The row attributes this record contributes to a
     * {@see FunctionInvocation}. Identity fields (site, source, method) belong
     * to the caller — a record knows the outcome, not who asked for it.
     *
     * @return array<string, mixed>
     */
    public function toRowAttributes(): array
    {
        return [
            'state' => FunctionInvocation::STATE_COMPLETED,
            'status_code' => $this->statusCode,
            'success' => $this->success,
            'duration_ms' => $this->durationMs,
            'wait_time_ms' => $this->waitTimeMs,
            'init_time_ms' => $this->initTimeMs,
            'cold' => $this->cold,
            'activation_id' => $this->activationId,
            'log_lines' => $this->logLines,
            'activation' => $this->raw,
            'result_excerpt' => $this->resultExcerpt,
        ];
    }

    /**
     * OpenWhisk annotations arrive as a `[{key, value}, …]` list; flatten it.
     *
     * @param  array<string, mixed>  $activation
     * @return array<string, mixed>
     */
    private static function annotations(array $activation): array
    {
        $flat = [];

        foreach ((array) data_get($activation, 'annotations', []) as $annotation) {
            if (is_array($annotation) && isset($annotation['key'])) {
                $flat[(string) $annotation['key']] = $annotation['value'] ?? null;
            }
        }

        return $flat;
    }

    /**
     * A bounded, human-readable excerpt of the activation result. The handler
     * returns {statusCode, headers, body}; the body is the useful part, so
     * prefer it and fall back to the whole result for any other shape.
     */
    private static function excerpt(mixed $result): ?string
    {
        if ($result === null) {
            return null;
        }

        if (is_array($result) && isset($result['body']) && is_string($result['body'])) {
            return Str::limit($result['body'], self::RESULT_EXCERPT_BYTES);
        }

        $text = is_string($result)
            ? $result
            : (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return Str::limit($text, self::RESULT_EXCERPT_BYTES);
    }
}
