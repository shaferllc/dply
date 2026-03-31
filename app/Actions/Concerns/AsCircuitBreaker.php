<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Actions\Decorators\CircuitBreakerDecorator;

/**
 * Implements circuit breaker pattern to prevent cascading failures.
 *
 * This trait is a marker that enables automatic circuit breaker functionality via CircuitBreakerDecorator.
 * When an action uses AsCircuitBreaker, CircuitBreakerDesignPattern recognizes it and
 * ActionManager wraps the action with CircuitBreakerDecorator.
 *
 * How it works:
 * 1. Action uses AsCircuitBreaker trait (marker)
 * 2. CircuitBreakerDesignPattern recognizes the trait
 * 3. ActionManager wraps action with CircuitBreakerDecorator
 * 4. When handle() is called, the decorator:
 *    - Checks if circuit is open (too many failures)
 *    - If open, throws exception or calls onCircuitOpen()
 *    - If closed/half-open, executes action
 *    - Records success/failure and updates circuit state
 *
 * Circuit States:
 * - Closed: Normal operation, failures are tracked
 * - Open: Too many failures, circuit is open, calls are blocked
 * - Half-Open: Timeout expired, testing if service recovered
 *
 * Features:
 * - Automatic failure tracking
 * - Circuit state management (closed/open/half-open)
 * - Configurable failure threshold
 * - Configurable timeout period
 * - Prevents cascading failures
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Prevents cascading failures
 * - Protects downstream services
 * - Automatic recovery testing
 * - Reduces load on failing services
 * - Composable with other decorators
 * - No trait method conflicts
 *
 * Use Cases:
 * - External API calls
 * - Database connections
 * - Third-party service integration
 * - Microservice communication
 * - Payment gateway calls
 * - Email service calls
 * - File storage operations
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * CircuitBreakerDecorator, which automatically wraps actions and adds circuit breaker functionality.
 * This follows the same pattern as AsDebounced, AsCostTracked, AsCompensatable, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `failureThreshold` property or `getFailureThreshold()` method (default: 5)
 * - Set `timeoutSeconds` property or `getTimeoutSeconds()` method (default: 60)
 * - Optionally implement `onCircuitOpen(...$arguments)` to customize open circuit behavior
 * - Optionally implement `getCircuitBreakerKey()` to customize cache key
 *
 * @example
 * // ============================================
 * // Example 1: Basic External API Call
 * // ============================================
 * class CallExternalAPI extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         return Http::get($endpoint)->json();
 *     }
 *
 *     // Optional: customize circuit breaker behavior
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
 * // Automatically opens circuit after failures, prevents calls during timeout
 * @example
 * // ============================================
 * // Example 2: Custom Circuit Open Handling
 * // ============================================
 * class ResilientAPICall extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         return Http::get($endpoint)->json();
 *     }
 *
 *     protected function onCircuitOpen(string $key, string $endpoint): array
 *     {
 *         // Return cached data or default response instead of throwing
 *         return Cache::get("fallback:{$endpoint}", ['status' => 'unavailable']);
 *     }
 * }
 *
 * // Returns fallback data when circuit is open instead of throwing exception
 * @example
 * // ============================================
 * // Example 3: Database Connection Circuit Breaker
 * // ============================================
 * class QueryExternalDatabase extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(string $query): array
 *     {
 *         return DB::connection('external')->select($query);
 *     }
 *
 *     protected function getFailureThreshold(): int
 *     {
 *         return 3; // Lower threshold for database
 *     }
 *
 *     protected function getTimeoutSeconds(): int
 *     {
 *         return 30; // Shorter timeout
 *     }
 * }
 *
 * // Protects against database connection failures
 * @example
 * // ============================================
 * // Example 4: Payment Gateway Circuit Breaker
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(Payment $payment): PaymentResult
 *     {
 *         return PaymentGateway::charge($payment);
 *     }
 *
 *     protected function getFailureThreshold(): int
 *     {
 *         return 10; // Higher threshold for critical operations
 *     }
 *
 *     protected function getTimeoutSeconds(): int
 *     {
 *         return 120; // Longer timeout
 *     }
 *
 *     protected function onCircuitOpen(string $key, Payment $payment): PaymentResult
 *     {
 *         // Queue payment for retry instead of failing immediately
 *         ProcessPaymentJob::dispatch($payment)->delay(now()->addMinutes(5));
 *
 *         return new PaymentResult([
 *             'status' => 'queued',
 *             'message' => 'Payment queued due to service unavailability',
 *         ]);
 *     }
 * }
 *
 * // Queues payments when circuit is open
 * @example
 * // ============================================
 * // Example 5: Email Service Circuit Breaker
 * // ============================================
 * class SendEmail extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(string $to, string $subject, string $body): void
 *     {
 *         Mail::to($to)->send(new CustomMail($subject, $body));
 *     }
 *
 *     protected function getFailureThreshold(): int
 *     {
 *         return 5;
 *     }
 *
 *     protected function onCircuitOpen(string $key, string $to, string $subject, string $body): void
 *     {
 *         // Log failed email instead of throwing
 *         Log::warning('Email service unavailable, message queued', [
 *             'to' => $to,
 *             'subject' => $subject,
 *         ]);
 *
 *         // Queue for later retry
 *         SendEmailJob::dispatch($to, $subject, $body);
 *     }
 * }
 *
 * // Queues emails when email service is down
 * @example
 * // ============================================
 * // Example 6: Microservice Communication
 * // ============================================
 * class CallUserService extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(int $userId): User
 *     {
 *         $response = Http::get("https://user-service.example.com/users/{$userId}");
 *         return new User($response->json());
 *     }
 *
 *     protected function getCircuitBreakerKey(): string
 *     {
 *         // Use service-specific key
 *         return 'circuit_breaker:user_service';
 *     }
 *
 *     protected function getFailureThreshold(): int
 *     {
 *         return 5;
 *     }
 *
 *     protected function onCircuitOpen(string $key, int $userId): ?User
 *     {
 *         // Try to get user from local cache
 *         return Cache::get("user:{$userId}");
 *     }
 * }
 *
 * // Falls back to cached data when user service is down
 * @example
 * // ============================================
 * // Example 7: File Storage Circuit Breaker
 * // ============================================
 * class UploadFile extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(string $path, string $content): void
 *     {
 *         Storage::disk('s3')->put($path, $content);
 *     }
 *
 *     protected function getFailureThreshold(): int
 *     {
 *         return 3;
 *     }
 *
 *     protected function getTimeoutSeconds(): int
 *     {
 *         return 30;
 *     }
 *
 *     protected function onCircuitOpen(string $key, string $path, string $content): void
 *     {
 *         // Store locally when S3 is unavailable
 *         Storage::disk('local')->put("pending/{$path}", $content);
 *
 *         // Queue sync job for when service recovers
 *         SyncToS3Job::dispatch($path);
 *     }
 * }
 *
 * // Stores locally when S3 is unavailable
 * @example
 * // ============================================
 * // Example 8: Property-Based Configuration
 * // ============================================
 * class ConfigurableCircuitBreaker extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public int $failureThreshold = 5;
 *     public int $timeoutSeconds = 60;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // Uses properties for configuration
 * @example
 * // ============================================
 * // Example 9: Multiple Circuit Breakers
 * // ============================================
 * class MultiServiceAction extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(): array
 *     {
 *         // Call multiple services
 *         $service1 = $this->callService1();
 *         $service2 = $this->callService2();
 *
 *         return ['service1' => $service1, 'service2' => $service2];
 *     }
 *
 *     protected function getCircuitBreakerKey(): string
 *     {
 *         // Use action-specific key
 *         return 'circuit_breaker:'.get_class($this).':'.md5(serialize(func_get_args()));
 *     }
 * }
 *
 * // Each action instance can have its own circuit breaker state
 * @example
 * // ============================================
 * // Example 10: Circuit Breaker with Retry
 * // ============================================
 * class RetryableServiceCall extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         return Http::retry(3, 100)->get($endpoint)->json();
 *     }
 *
 *     protected function getFailureThreshold(): int
 *     {
 *         return 10; // Higher threshold since we retry internally
 *     }
 * }
 *
 * // Combines internal retry with circuit breaker protection
 * @example
 * // ============================================
 * // Example 11: Monitoring Circuit State
 * // ============================================
 * class MonitoredServiceCall extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(): void
 *     {
 *         // Service call
 *     }
 *
 *     protected function onCircuitOpen(string $key): void
 *     {
 *         $decorator = $this->getCircuitBreakerDecorator();
 *         $state = $decorator->getCircuitState();
 *
 *         // Send alert when circuit opens
 *         Log::critical('Circuit breaker opened', [
 *             'key' => $key,
 *             'failures' => $state['failures'],
 *             'last_failure' => $state['last_failure'],
 *         ]);
 *
 *         // Send notification
 *         Notification::route('slack', '#alerts')
 *             ->notify(new CircuitBreakerOpened($key, $state));
 *     }
 *
 *     protected function getCircuitBreakerDecorator(): ?CircuitBreakerDecorator
 *     {
 *         return $this->_circuitBreakerDecorator ?? null;
 *     }
 * }
 *
 * // Monitors and alerts when circuit opens
 * @example
 * // ============================================
 * // Example 12: Testing Circuit Breaker
 * // ============================================
 * class TestableCircuitBreaker extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(): string
 *     {
 *         return 'success';
 *     }
 *
 *     protected function getFailureThreshold(): int
 *     {
 *         return 2; // Low threshold for testing
 *     }
 *
 *     protected function getTimeoutSeconds(): int
 *     {
 *         return 5; // Short timeout for testing
 *     }
 * }
 *
 * // In tests:
 * // // Force failures to open circuit
 * // try {
 * //     TestableCircuitBreaker::run(); // Should succeed
 * // } catch (\Exception $e) {
 * //     // Simulate failure
 * // }
 * //
 * // // After threshold failures, circuit should be open
 * // expect(fn() => TestableCircuitBreaker::run())
 * //     ->toThrow(\RuntimeException::class, 'Circuit breaker is open');
 * @example
 * // ============================================
 * // Example 13: Circuit Breaker with Fallback
 * // ============================================
 * class ResilientDataFetch extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(int $id): array
 *     {
 *         return Http::get("https://api.example.com/data/{$id}")->json();
 *     }
 *
 *     protected function onCircuitOpen(string $key, int $id): array
 *     {
 *         // Try multiple fallback strategies
 *         if ($cached = Cache::get("data:{$id}")) {
 *             return $cached;
 *         }
 *
 *         if ($local = DB::table('data_cache')->where('id', $id)->first()) {
 *             return (array) $local;
 *         }
 *
 *         // Return default/empty data
 *         return ['id' => $id, 'status' => 'unavailable'];
 *     }
 * }
 *
 * // Tries multiple fallback strategies when circuit is open
 * @example
 * // ============================================
 * // Example 14: Environment-Specific Thresholds
 * // ============================================
 * class EnvironmentAwareCircuitBreaker extends Actions
 * {
 *     use AsCircuitBreaker;
 *
 *     public function handle(): void
 *     {
 *         // Service call
 *     }
 *
 *     protected function getFailureThreshold(): int
 *     {
 *         return match(app()->environment()) {
 *             'production' => 10,
 *             'staging' => 5,
 *             'local' => 2,
 *             default => 5,
 *         };
 *     }
 *
 *     protected function getTimeoutSeconds(): int
 *     {
 *         return match(app()->environment()) {
 *             'production' => 120,
 *             'staging' => 60,
 *             'local' => 10,
 *             default => 60,
 *         };
 *     }
 * }
 *
 * // Different thresholds per environment
 */
trait AsCircuitBreaker
{
    /**
     * Reference to the circuit breaker decorator (injected by decorator).
     */
    protected ?CircuitBreakerDecorator $_circuitBreakerDecorator = null;

    /**
     * Set the circuit breaker decorator reference.
     *
     * Called by CircuitBreakerDecorator to inject itself.
     */
    public function setCircuitBreakerDecorator(CircuitBreakerDecorator $decorator): void
    {
        $this->_circuitBreakerDecorator = $decorator;
    }

    /**
     * Get the circuit breaker decorator.
     */
    protected function getCircuitBreakerDecorator(): ?CircuitBreakerDecorator
    {
        return $this->_circuitBreakerDecorator;
    }

    /**
     * Get the current circuit breaker state.
     *
     * @return array{state: string, failures: int, last_failure: int|null}
     */
    public function getCircuitState(): array
    {
        $decorator = $this->getCircuitBreakerDecorator();
        if ($decorator) {
            return $decorator->getCircuitState();
        }

        return [
            'state' => 'closed',
            'failures' => 0,
            'last_failure' => null,
        ];
    }

    /**
     * Reset the circuit breaker.
     */
    public function resetCircuit(): void
    {
        $decorator = $this->getCircuitBreakerDecorator();
        if ($decorator) {
            $decorator->resetCircuit();
        }
    }
}
