<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsCircuitBreaker;
use App\Actions\Decorators\CircuitBreakerDecorator;

/**
 * Recognizes when actions use circuit breaker pattern capabilities.
 *
 * @example
 * // Action class:
 * class CallExternalAPI extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         return Http::get($endpoint)->json();
 *     }
 *
 *     protected function getFailureThreshold(): int
 *     {
 *         return 5; // Open circuit after 5 failures
 *     }
 *
 *     protected function getTimeoutSeconds(): int
 *     {
 *         return 60; // Keep circuit open for 60 seconds
 *     }
 * }
 *
 * // Usage:
 * CallExternalAPI::run('https://api.example.com/data');
 * // Automatically opens circuit after failures, prevents calls during timeout
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsCircuitBreaker and decorates it to prevent cascading failures.
 */
class CircuitBreakerDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsCircuitBreaker::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsCircuitBreaker trait
        // The decorator will handle circuit breaker logic
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(CircuitBreakerDecorator::class, ['action' => $instance]);
    }
}
