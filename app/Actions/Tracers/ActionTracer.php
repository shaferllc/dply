<?php

namespace App\Actions\Tracers;

use Illuminate\Support\Facades\Log;

/**
 * Action Tracer
 *
 * Custom tracer implementation for action execution tracking.
 * Provides distributed tracing capabilities with support for:
 * - Trace ID and Span ID generation
 * - Span start/end recording
 * - Custom attributes
 * - Success/failure tracking
 * - Exception logging
 *
 * This tracer can be extended or replaced with OpenTelemetry,
 * Jaeger, or other distributed tracing systems.
 */
class ActionTracer
{
    /**
     * Start a new trace span.
     *
     * @param  string  $name  The span name (typically the action class name)
     * @param  string  $traceId  The trace ID
     * @param  string  $spanId  The span ID
     * @param  array  $attributes  Custom attributes for the span
     */
    public function startSpan(string $name, string $traceId, string $spanId, array $attributes = []): void
    {
        // Log span start for debugging
        if (config('actions.tracing.log_enabled', true)) {
            Log::debug('Tracer: Span started', [
                'name' => $name,
                'trace_id' => $traceId,
                'span_id' => $spanId,
                'attributes' => $attributes,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        // Integration point for OpenTelemetry or other tracing systems
        // Example: OpenTelemetry\SDK\Trace\TracerProvider
        // $tracer = $tracerProvider->getTracer('laravel-actions');
        // $span = $tracer->spanBuilder($name)
        //     ->setAttribute('trace_id', $traceId)
        //     ->setAttribute('span_id', $spanId)
        //     ->startSpan();
    }

    /**
     * End a trace span.
     *
     * @param  string  $traceId  The trace ID
     * @param  string  $spanId  The span ID
     * @param  bool  $success  Whether the operation succeeded
     * @param  \Throwable|null  $exception  Exception if operation failed
     */
    public function endSpan(string $traceId, string $spanId, bool $success, ?\Throwable $exception = null): void
    {
        // Log span end for debugging
        if (config('actions.tracing.log_enabled', true)) {
            Log::debug('Tracer: Span ended', [
                'trace_id' => $traceId,
                'span_id' => $spanId,
                'success' => $success,
                'exception' => $exception ? get_class($exception) : null,
                'exception_message' => $exception?->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        // Integration point for OpenTelemetry or other tracing systems
        // Example: $span->setStatus($success ? StatusCode::OK : StatusCode::ERROR);
        // if ($exception) {
        //     $span->recordException($exception);
        // }
        // $span->end();
    }

    /**
     * Generate a unique trace ID.
     *
     * @return string 32-character hexadecimal trace ID
     */
    public function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate a unique span ID.
     *
     * @return string 16-character hexadecimal span ID
     */
    public function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
