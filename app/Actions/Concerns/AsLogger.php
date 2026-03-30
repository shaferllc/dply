<?php

namespace App\Actions\Concerns;

/**
 * Automatically logs action execution, parameters, and results.
 *
 * This trait is a marker that enables automatic logging via LoggerDecorator.
 * When an action uses AsLogger, LoggerDesignPattern recognizes it and
 * ActionManager wraps the action with LoggerDecorator.
 *
 * How it works:
 * 1. Action uses AsLogger trait (marker)
 * 2. LoggerDesignPattern recognizes the trait
 * 3. ActionManager wraps action with LoggerDecorator
 * 4. When handle() is called, the decorator:
 *    - Logs action start with sanitized parameters
 *    - Executes the action
 *    - Logs success with result and duration
 *    - On exception, logs failure with exception details
 *    - Returns the result (or re-throws exception)
 *
 * Benefits:
 * - Automatic logging without modifying action logic
 * - Consistent log format across all actions
 * - Sensitive data protection (automatic redaction)
 * - Performance monitoring (duration tracking)
 * - Error tracking with full context
 * - Configurable log channels and levels
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * LoggerDecorator, which automatically wraps actions and adds logging.
 * This follows the same pattern as AsMetrics, AsObservable, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `getLogChannel()` method to customize log channel (default: config('logging.default'))
 * - Set `logChannel` property to customize log channel
 * - Set `getLogLevel()` method to customize log level (default: 'info')
 * - Set `logLevel` property to customize log level
 * - Set `getSensitiveParameters()` method to customize which parameters to redact
 *
 * @example
 * // ============================================
 * // Example 1: Basic Logging
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(Order $order, float $amount): bool
 *     {
 *         // Payment processing logic
 *         return true;
 *     }
 * }
 *
 * // Usage:
 * ProcessPayment::run($order, 100.00);
 * // Automatically logs:
 * // - "Action started" with order and amount parameters
 * // - "Action completed" with result and execution duration
 * @example
 * // ============================================
 * // Example 2: Custom Log Channel
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(Order $order, float $amount): bool
 *     {
 *         return true;
 *     }
 *
 *     // Log to a dedicated payments channel
 *     protected function getLogChannel(): string
 *     {
 *         return 'payments';
 *     }
 * }
 *
 * // Configure in config/logging.php:
 * // 'channels' => [
 * //     'payments' => [
 * //         'driver' => 'daily',
 * //         'path' => storage_path('logs/payments.log'),
 * //     ],
 * // ],
 * @example
 * // ============================================
 * // Example 3: Custom Log Level
 * // ============================================
 * class CriticalOperation extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(): void
 *     {
 *         // Critical operation
 *     }
 *
 *     // Use warning level for all logs
 *     protected function getLogLevel(): string
 *     {
 *         return 'warning';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Sensitive Parameter Protection
 * // ============================================
 * class AuthenticateUser extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(string $email, string $password): bool
 *     {
 *         // Authentication logic
 *         return true;
 *     }
 *
 *     // Protect sensitive data from logs
 *     protected function getSensitiveParameters(): array
 *     {
 *         return ['password', 'password_confirmation', 'token', 'api_key', 'secret'];
 *     }
 * }
 *
 * // Usage:
 * AuthenticateUser::run('user@example.com', 'secret123');
 * // Logs will show: ['user@example.com', '***REDACTED***']
 * @example
 * // ============================================
 * // Example 5: Property-Based Configuration
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsLogger;
 *
 *     protected string $logChannel = 'orders';
 *     protected string $logLevel = 'info';
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process order
 *     }
 * }
 *
 * // Properties are automatically detected and used
 * @example
 * // ============================================
 * // Example 6: Error Logging
 * // ============================================
 * class RiskyOperation extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(): void
 *     {
 *         throw new \RuntimeException('Operation failed');
 *     }
 * }
 *
 * // Usage:
 * try {
 *     RiskyOperation::run();
 * } catch (\RuntimeException $e) {
 *     // Exception is automatically logged with:
 *     // - Exception class and message
 *     // - Full stack trace
 *     // - Execution duration
 *     // - All parameters (sanitized)
 * }
 * @example
 * // ============================================
 * // Example 7: Complex Parameters
 * // ============================================
 * class CreateUser extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(array $userData, User $createdBy): User
 *     {
 *         // Create user with nested data
 *         return User::create($userData);
 *     }
 *
 *     protected function getSensitiveParameters(): array
 *     {
 *         return ['password', 'ssn', 'credit_card'];
 *     }
 * }
 *
 * // Usage:
 * CreateUser::run([
 *     'name' => 'John Doe',
 *     'email' => 'john@example.com',
 *     'password' => 'secret',
 *     'profile' => [
 *         'ssn' => '123-45-6789',
 *     ],
 * ], $admin);
 *
 * // Logs will sanitize nested sensitive data:
 * // [
 * //     'name' => 'John Doe',
 * //     'email' => 'john@example.com',
 * //     'password' => '***REDACTED***',
 * //     'profile' => [
 * //         'ssn' => '***REDACTED***',
 * //     ],
 * // ],
 * // 'createdBy' => 'App\Models\User (object)'
 * @example
 * // ============================================
 * // Example 8: Performance Monitoring
 * // ============================================
 * class GenerateReport extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(User $user): array
 *     {
 *         // Expensive operation
 *         sleep(2);
 *         return ['data' => 'report'];
 *     }
 * }
 *
 * // Usage:
 * GenerateReport::run($user);
 * // Logs include: 'duration_ms' => 2000.45
 * // Useful for monitoring slow operations
 * @example
 * // ============================================
 * // Example 9: Combining with Other Decorators
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsLogger;
 *     use AsMetrics;
 *     use AsTransaction;
 *
 *     public function handle(Order $order, float $amount): bool
 *     {
 *         // Payment logic
 *         return true;
 *     }
 *
 *     protected function getLogChannel(): string
 *     {
 *         return 'payments';
 *     }
 * }
 *
 * // All decorators work together:
 * // - LoggerDecorator logs execution
 * // - MetricsDecorator tracks performance
 * // - TransactionDecorator ensures database consistency
 * @example
 * // ============================================
 * // Example 10: API Request Logging
 * // ============================================
 * class CallExternalAPI extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(string $endpoint, array $data): array
 *     {
 *         // API call logic
 *         return ['status' => 'success'];
 *     }
 *
 *     protected function getLogChannel(): string
 *     {
 *         return 'api';
 *     }
 *
 *     protected function getLogLevel(): string
 *     {
 *         return 'debug'; // More verbose for API calls
 *     }
 *
 *     protected function getSensitiveParameters(): array
 *     {
 *         return ['api_key', 'token', 'secret', 'authorization'];
 *     }
 * }
 *
 * // Logs all API calls with full context for debugging
 * @example
 * // ============================================
 * // Example 11: Background Job Logging
 * // ============================================
 * class ProcessEmailQueue extends Actions implements \Illuminate\Contracts\Queue\ShouldQueue
 * {
 *     use AsLogger;
 *
 *     public function handle(Email $email): void
 *     {
 *         // Send email
 *     }
 *
 *     protected function getLogChannel(): string
 *     {
 *         return 'jobs';
 *     }
 * }
 *
 * // Queue the job - logging happens when job executes
 * ProcessEmailQueue::dispatch($email);
 * @example
 * // ============================================
 * // Example 12: Environment-Based Logging
 * // ============================================
 * class DevelopmentOnlyAction extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function getLogLevel(): string
 *     {
 *         // More verbose in development
 *         return app()->environment('local') ? 'debug' : 'info';
 *     }
 *
 *     protected function getLogChannel(): string
 *     {
 *         // Use different channel in production
 *         return app()->environment('production') ? 'production' : 'development';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: Structured Logging for Analytics
 * // ============================================
 * class AnalyticsEvent extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(string $event, array $data): void
 *     {
 *         // Track event
 *     }
 *
 *     protected function getLogChannel(): string
 *     {
 *         return 'analytics';
 *     }
 * }
 *
 * // Configure analytics channel to write to a separate log file
 * // or send to external analytics service
 * @example
 * // ============================================
 * // Example 14: Multi-Channel Logging
 * // ============================================
 * class CriticalPayment extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(Payment $payment): void
 *     {
 *         // Process critical payment
 *     }
 *
 *     protected function getLogChannel(): string
 *     {
 *         // Log to both payments and critical channels
 *         // (requires custom decorator or multiple logging calls)
 *         return 'payments';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Logging with Request Context
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process order
 *     }
 *
 *     protected function getLogChannel(): string
 *     {
 *         return 'orders';
 *     }
 * }
 *
 * // The decorator automatically includes:
 * // - Action class name
 * // - Sanitized parameters
 * // - Execution duration
 * // - Timestamp
 * // All in a consistent format for easy parsing
 */
trait AsLogger
{
    // This is a marker trait - the actual logging is handled by LoggerDecorator
    // via the LoggerDesignPattern and ActionManager
}
