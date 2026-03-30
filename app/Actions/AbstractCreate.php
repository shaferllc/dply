<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Attributes\TransactionAttempts;
use App\Actions\Attributes\Transformations;
use App\Actions\Attributes\TransformMode;
use App\Actions\Attributes\ValidationRules;
use App\Actions\Attributes\WatermarkEnabled;
use App\Actions\Attributes\WatermarkMode;
use App\Actions\Concerns\AsAction;
use App\Actions\Concerns\AsAuditable;
use App\Actions\Concerns\AsAuthenticated;
use App\Actions\Concerns\AsAuthorized;
use App\Actions\Concerns\AsBroadcast;
use App\Actions\Concerns\AsDependent;
use App\Actions\Concerns\AsEvent;
use App\Actions\Concerns\AsMetrics;
use App\Actions\Concerns\AsRetry;
use App\Actions\Concerns\AsTracer;
use App\Actions\Concerns\AsTransaction;
use App\Actions\Concerns\AsTransformer;
use App\Actions\Concerns\AsValidated;
use App\Actions\Concerns\AsWatermarked;
use App\Actions\Concerns\AsWebhook;
use App\Actions\Helpers\ArgumentExtractor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Abstract Base Class for Create Actions
 *
 * This abstract class provides a comprehensive baseline for all create actions
 * in the application. It includes essential concerns, useful optional concerns,
 * and nice-to-have concerns that most create actions will benefit from.
 *
 * Essential Concerns Included:
 * - AsValidated: Input validation
 * - AsTransaction: Database transaction wrapping
 * - AsAuthenticated: Authentication requirement
 * - AsAuthorized: Permission checks
 * - AsAuditable: Audit trail logging
 * - AsMetrics: Performance tracking
 * - AsDependent: Dependency declaration
 *
 * Useful Optional Concerns Included:
 * - AsWatermarked: Execution metadata
 * - AsTracer: Distributed tracing
 * - AsTransformer: Result transformation
 *
 * Nice-to-Have Concerns Included:
 * - AsWebhook: External system notifications
 * - AsEvent: Laravel event dispatching
 * - AsRetry: Automatic retry on failures
 * - AsBroadcast: Real-time updates
 *
 * Usage:
 *
 * ```php
 * #[ValidationRules([
 *     'name' => 'required|string|max:255',
 * ])]
 * class CreateTag extends AbstractCreate
 * {
 *     public function handle(Team $team, array $formData): Tag
 *     {
 *         // Your creation logic here
 *         return Tag::create($formData);
 *     }
 * }
 * ```
 *
 * Override Methods:
 * - `handle(...$arguments)` - REQUIRED: Implement your creation logic
 * - `getAuthorizationAbility()` - Customize permission check (default: 'create')
 * - `getAuthorizationArguments(...$arguments)` - Customize authorization arguments
 * - `getDependencies()` - Declare action dependencies
 * - `getAuditData($result, array $arguments)` - Customize audit data
 * - `getWatermarkData()` - Customize watermark metadata
 * - `getTraceAttributes(array $arguments)` - Customize trace attributes
 * - `getTraceName()` - Customize trace name
 * - `getTransformations()` - Customize result transformations
 * - `getWebhookPayload($result, array $arguments)` - Customize webhook payload
 * - `onWebhookSuccess($response, array $payload)` - Handle webhook success
 * - `onWebhookFailure($exception, array $payload)` - Handle webhook failure
 * - `getMaxRetries()` - Configure retry attempts (default: 3)
 * - `getRetryBaseDelay()` - Configure retry delay (default: 1000ms)
 * - `shouldRetry($exception)` - Determine if exception should be retried
 * - `getBroadcastChannel(...$arguments)` - Configure broadcast channel
 * - `getBroadcastEventName()` - Configure broadcast event name
 * - `getBroadcastPayload($result, array $arguments)` - Customize broadcast payload
 */
#[TransactionAttempts(1)]
#[WatermarkMode('append')]
#[WatermarkEnabled(true)]
#[TransformMode('nested')]
abstract class AbstractCreate
{
    use AsAction;
    use AsAuditable;
    use AsAuthenticated;
    use AsAuthorized;
    use AsBroadcast;
    use AsDependent;
    use AsEvent;
    use AsMetrics;
    use AsRetry;
    use AsTracer;
    use AsTransaction;
    use AsTransformer;
    use AsValidated;
    use AsWatermarked;
    use AsWebhook;

    /**
     * Handle the creation logic.
     *
     * This method must be implemented by subclasses to provide
     * the actual creation logic. Subclasses can define their own
     * method signature with typed parameters and return types.
     *
     * Example:
     * public function handle(Team $team, array $formData): Tag
     * {
     *     // Creation logic
     * }
     *
     * Note: This method is not abstract to allow subclasses to define
     * their own signatures. The ActionManager will call it dynamically.
     */

    /**
     * Get the authorization ability for this action.
     *
     * Used by AsAuthorized to check permissions.
     * Override to customize the permission check.
     *
     * @return string The permission ability name
     */
    protected function getAuthorizationAbility(): string
    {
        return 'create';
    }

    /**
     * Get the authorization arguments for this action.
     *
     * Used by AsAuthorized to pass context to permission checks.
     * Default implementation passes all arguments.
     *
     * @param  mixed  ...$arguments  The action arguments
     * @return array Arguments to pass to the Gate
     */
    protected function getAuthorizationArguments(...$arguments): array
    {
        return $arguments;
    }

    /**
     * Get the dependencies for this action.
     *
     * Used by AsDependent to declare action dependencies.
     * Override to declare dependencies.
     *
     * @return array<string> Array of action class names this action depends on
     */
    protected function getDependencies(): array
    {
        return [];
    }

    /**
     * Customize audit data for this action.
     *
     * Used by AsAuditable to add custom data to audit logs.
     * Override to add custom audit data.
     *
     * @param  mixed  $result  The action result
     * @param  array  $arguments  The action arguments
     * @return array Custom audit data to merge
     */
    protected function getAuditData($result, array $arguments): array
    {
        return [];
    }

    /**
     * Customize watermark data for this action.
     *
     * Used by AsWatermarked to add custom metadata to results.
     * Override to add custom watermark data.
     *
     * @return array Custom watermark data to merge
     */
    protected function getWatermarkData(): array
    {
        return [
            'environment' => app()->environment(),
        ];
    }

    /**
     * Customize trace attributes for this action.
     *
     * Used by AsTracer to add custom attributes to trace spans.
     * Override to add custom trace attributes.
     *
     * @param  array  $arguments  The action arguments
     * @return array Custom trace attributes
     */
    protected function getTraceAttributes(array $arguments): array
    {
        return [];
    }

    /**
     * Customize trace name for this action.
     *
     * Used by AsTracer to set a custom trace/span name.
     * Defaults to class name if not overridden.
     *
     * @return string Custom trace name
     */
    protected function getTraceName(): string
    {
        // Default: use class name without namespace, lowercase with dots
        $className = class_basename(static::class);
        $module = $this->getModuleName();

        return $module ? "{$module}.create" : strtolower($className);
    }

    /**
     * Get module name from class namespace.
     *
     * Extracts module name from namespace for trace naming.
     *
     * @return string|null Module name or null
     */
    protected function getModuleName(): ?string
    {
        $namespace = static::class;
        if (preg_match('/App\\\\Modules\\\\([^\\\\]+)\\\\/', $namespace, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Customize transformations for this action.
     *
     * Used by AsTransformer to transform result data structure.
     * Override to customize transformations.
     *
     * @return array Transformation rules
     */
    protected function getTransformations(): array
    {
        return [];
    }

    /**
     * Customize webhook payload for this action.
     *
     * Used by AsWebhook to format the payload sent to external systems.
     * Override to customize webhook payload.
     *
     * @param  mixed  $result  The action result
     * @param  array  $arguments  The action arguments
     * @return array Webhook payload
     */
    protected function getWebhookPayload($result, array $arguments): array
    {
        return [
            'id' => $result->id ?? null,
            'created_at' => $result->created_at?->toIso8601String(),
        ];
    }

    /**
     * Handle webhook success.
     *
     * Called by AsWebhook after successful webhook dispatch.
     * Override to handle webhook success.
     *
     * @param  Response  $response  The HTTP response
     * @param  array  $payload  The webhook payload
     */
    protected function onWebhookSuccess(Response $response, array $payload): void
    {
        // Override to handle webhook success
    }

    /**
     * Handle webhook failure.
     *
     * Called by AsWebhook if webhook dispatch fails.
     * Override to handle webhook failure.
     *
     * @param  \Throwable  $exception  The exception that occurred
     * @param  array  $payload  The webhook payload
     */
    protected function onWebhookFailure(\Throwable $exception, array $payload): void
    {
        // Override to handle webhook failure
    }

    /**
     * Configure retry behavior for this action.
     *
     * Used by AsRetry to determine maximum retry attempts.
     * Return 0 to disable retries.
     *
     * @return int Maximum number of retry attempts
     */
    protected function getMaxRetries(): int
    {
        return 3; // Default: retry up to 3 times
    }

    /**
     * Configure retry base delay for this action.
     *
     * Used by AsRetry for exponential backoff calculation.
     * Delay increases exponentially: baseDelay * 2^attempt
     *
     * @return int Base delay in milliseconds
     */
    protected function getRetryBaseDelay(): int
    {
        return 1000; // Default: 1 second base delay
    }

    /**
     * Determine if exception should be retried.
     *
     * Used by AsRetry to filter which exceptions should trigger retries.
     * Return true to retry, false to fail immediately.
     *
     * @param  \Throwable  $exception  The exception that occurred
     * @return bool Whether to retry on this exception
     */
    protected function shouldRetry(\Throwable $exception): bool
    {
        // Default: retry on database deadlocks and connection errors
        return match (true) {
            $exception instanceof QueryException => true,
            $exception instanceof ConnectionException => true,
            default => false,
        };
    }

    /**
     * Configure broadcast channel for this action.
     *
     * Used by AsBroadcast to determine which channel to broadcast to.
     * Override to customize broadcast channel.
     *
     * @param  mixed  ...$arguments  The action arguments
     * @return string|array Broadcast channel name(s)
     */
    protected function getBroadcastChannel(...$arguments): string|array
    {
        // Default: no broadcasting (return empty or override)
        return [];
    }

    /**
     * Configure broadcast event name for this action.
     *
     * Used by AsBroadcast to customize the event name.
     * Override to customize broadcast event name.
     *
     * @return string Broadcast event name
     */
    protected function getBroadcastEventName(): string
    {
        $module = $this->getModuleName();
        $resource = $this->getResourceName();

        return $module && $resource ? "{$module}.{$resource}.created" : 'resource.created';
    }

    /**
     * Get resource name from class name.
     *
     * Extracts resource name from class name for broadcast naming.
     *
     * @return string Resource name
     */
    protected function getResourceName(): string
    {
        $className = class_basename(static::class);
        // Remove "Create" prefix if present
        $resource = preg_replace('/^Create/', '', $className);

        return strtolower($resource);
    }

    /**
     * Customize broadcast payload for this action.
     *
     * Used by AsBroadcast to format the payload sent to frontend.
     * Override to customize broadcast payload.
     *
     * @param  mixed  $result  The action result
     * @param  array  $arguments  The action arguments
     * @return array Broadcast payload
     */
    protected function getBroadcastPayload($result, array $arguments): array
    {
        return [
            'id' => $result->id ?? null,
            'created_at' => $result->created_at?->toIso8601String(),
        ];
    }

    /**
     * Extract and type arguments from variadic array.
     *
     * Helper method that delegates to ArgumentExtractor for consistency.
     * This method is kept for backward compatibility and convenience.
     *
     * @param  array  $arguments  The variadic arguments array
     * @param  string|null  ...$types  Optional type hints for each argument
     * @return array Extracted arguments
     *
     * @example
     * // Extract two arguments: Team and array
     * [$team, $formData] = $this->extractArguments($arguments, Team::class, 'array');
     */
    protected function extractArguments(array $arguments, ?string ...$types): array
    {
        return ArgumentExtractor::extract($arguments, ...$types);
    }

    // ============================================================================
    // Utility Methods - Leverage the Power of All Concerns
    // ============================================================================

    /**
     * Dispatch this action as an event after successful creation.
     *
     * Automatically dispatches the action as a Laravel event, allowing
     * other parts of the application to react to the creation.
     *
     * @param  mixed  ...$arguments  The action arguments
     *
     * @example
     * // In your handle() method after creation:
     * $tag = Tag::create($formData);
     * $this->dispatchCreatedEvent($tag, $team);
     */
    protected function dispatchCreatedEvent(...$arguments): void
    {
        static::dispatch(...$arguments);
    }

    /**
     * Dispatch this action as an event conditionally.
     *
     * Only dispatches if the condition is true.
     *
     * @param  bool  $condition  Whether to dispatch
     * @param  mixed  ...$arguments  The action arguments
     */
    protected function dispatchCreatedEventIf(bool $condition, ...$arguments): void
    {
        static::dispatchIf($condition, ...$arguments);
    }

    /**
     * Get current authenticated user.
     *
     * Convenience method for accessing the authenticated user.
     *
     * @return Authenticatable|null
     */
    protected function currentUser()
    {
        return Auth::user();
    }

    /**
     * Get current user ID.
     *
     * Convenience method for accessing the authenticated user's ID.
     */
    protected function currentUserId(): int|string|null
    {
        return Auth::id();
    }

    /**
     * Log an info message with action context.
     *
     * Automatically includes action class name and trace information.
     *
     * @param  string  $message  The log message
     * @param  array  $context  Additional context
     */
    protected function logInfo(string $message, array $context = []): void
    {
        Log::info("[{$this->getActionName()}] {$message}", array_merge([
            'action' => static::class,
            'user_id' => $this->currentUserId(),
        ], $context));
    }

    /**
     * Log an error message with action context.
     *
     * Automatically includes action class name and trace information.
     *
     * @param  string  $message  The log message
     * @param  \Throwable|null  $exception  Optional exception
     * @param  array  $context  Additional context
     */
    protected function logError(string $message, ?\Throwable $exception = null, array $context = []): void
    {
        Log::error("[{$this->getActionName()}] {$message}", array_merge([
            'action' => static::class,
            'user_id' => $this->currentUserId(),
            'exception' => $exception?->getMessage(),
        ], $context));
    }

    /**
     * Get action name for logging and tracing.
     *
     * Returns a human-readable name for the action.
     *
     * @return string Action name
     */
    protected function getActionName(): string
    {
        return class_basename(static::class);
    }

    /**
     * Get performance metrics for this action.
     *
     * Retrieves metrics tracked by AsMetrics concern.
     * Note: This is an instance method that wraps ActionMetrics::getMetrics().
     * Use static::getMetrics() for the static method from AsMetrics trait.
     *
     * @return array Performance metrics
     */
    public function getActionMetrics(): array
    {
        return ActionMetrics::getMetrics(static::class);
    }

    /**
     * Check if action has been called recently.
     *
     * Useful for rate limiting or duplicate prevention checks.
     *
     * @param  int  $seconds  Time window in seconds (default: 60)
     * @return bool Whether action was called recently
     */
    protected function wasCalledRecently(int $seconds = 60): bool
    {
        $metrics = $this->getActionMetrics();
        $lastCall = $metrics['last_call_at'] ?? null;

        if (! $lastCall) {
            return false;
        }

        return now()->diffInSeconds($lastCall) < $seconds;
    }

    /**
     * Get success rate for this action.
     *
     * Returns the success rate as a percentage (0-100).
     *
     * @return float Success rate percentage
     */
    protected function getSuccessRate(): float
    {
        $metrics = $this->getActionMetrics();
        $successRate = $metrics['success_rate'] ?? 0;

        return round($successRate * 100, 2);
    }

    /**
     * Check if action is performing well.
     *
     * Returns true if success rate is above threshold and average duration is acceptable.
     *
     * @param  float  $minSuccessRate  Minimum success rate (0-1, default: 0.95)
     * @param  int  $maxAvgDurationMs  Maximum average duration in milliseconds (default: 1000)
     * @return bool Whether action is performing well
     */
    protected function isPerformingWell(float $minSuccessRate = 0.95, int $maxAvgDurationMs = 1000): bool
    {
        $metrics = $this->getActionMetrics();
        $successRate = $metrics['success_rate'] ?? 0;
        $avgDuration = $metrics['avg_duration_ms'] ?? 0;

        return $successRate >= $minSuccessRate && $avgDuration <= $maxAvgDurationMs;
    }

    /**
     * Create a standardized success response array.
     *
     * Returns a consistent response structure for successful creations.
     * Note: This is a data structure helper, not an HTTP response.
     * Use AsResponse concern for HTTP responses.
     *
     * @param  mixed  $result  The created resource
     * @param  string  $message  Success message
     * @param  array  $metadata  Additional metadata
     * @return array Standardized response data
     */
    protected function successResponseData($result, string $message = 'Created successfully', array $metadata = []): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $result,
            'metadata' => array_merge([
                'action' => static::class,
                'created_at' => now()->toIso8601String(),
                'created_by' => $this->currentUserId(),
            ], $metadata),
        ];
    }

    /**
     * Create a standardized error response array.
     *
     * Returns a consistent response structure for errors.
     * Note: This is a data structure helper, not an HTTP response.
     * Use AsResponse concern for HTTP responses.
     *
     * @param  string  $message  Error message
     * @param  array  $errors  Validation errors or additional error details
     * @param  int  $code  Error code
     * @return array Standardized error response data
     */
    protected function errorResponseData(string $message, array $errors = [], int $code = 400): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'code' => $code,
            'metadata' => [
                'action' => static::class,
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Validate and sanitize input data.
     *
     * Helper method for common validation and sanitization patterns.
     *
     * @param  array  $data  Input data
     * @param  array  $rules  Validation rules
     * @return array Validated and sanitized data
     */
    protected function validateAndSanitize(array $data, array $rules): array
    {
        $validated = validator($data, $rules)->validate();

        // Common sanitization
        foreach ($validated as $key => $value) {
            if (is_string($value)) {
                $validated[$key] = trim($value);
            }
        }

        return $validated;
    }

    /**
     * Get request ID for tracing.
     *
     * Retrieves or generates a request ID for distributed tracing.
     *
     * @return string Request ID
     */
    protected function getRequestId(): string
    {
        return request()->header('X-Request-ID') ?? (string) Str::uuid();
    }

    /**
     * Check if action should be executed based on feature flags or conditions.
     *
     * Override this method to add conditional execution logic.
     *
     * @param  array  $arguments  The action arguments
     * @return bool Whether action should execute
     */
    protected function shouldExecute(array $arguments): bool
    {
        return true; // Override to add custom logic
    }

    /**
     * Prepare data before creation.
     *
     * Override this method to transform or prepare data before it's used in handle().
     *
     * @param  array  $data  Raw input data
     * @return array Prepared data
     */
    protected function prepareData(array $data): array
    {
        // Add common preparation logic here
        // e.g., set timestamps, user IDs, etc.
        return $data;
    }

    /**
     * Perform post-creation tasks.
     *
     * Override this method to perform tasks after successful creation.
     *
     * @param  mixed  $result  The created resource
     * @param  array  $arguments  The action arguments
     */
    protected function afterCreate($result, array $arguments): void
    {
        // Override to perform post-creation tasks
        // e.g., send notifications, update related models, etc.
    }

    /**
     * Perform pre-creation tasks.
     *
     * Override this method to perform tasks before creation.
     *
     * @param  array  $arguments  The action arguments
     */
    protected function beforeCreate(array $arguments): void
    {
        // Override to perform pre-creation tasks
        // e.g., validate business rules, check quotas, etc.
    }

    /**
     * Get default attributes for created resources.
     *
     * Returns common attributes that should be set on created resources.
     *
     * @return array Default attributes
     */
    protected function getDefaultAttributes(): array
    {
        return [
            'created_by' => $this->currentUserId(),
            'created_at' => now(),
        ];
    }

    /**
     * Merge default attributes with provided data.
     *
     * Combines default attributes with user-provided data.
     *
     * @param  array  $data  User-provided data
     * @return array Merged data with defaults
     */
    protected function mergeDefaults(array $data): array
    {
        return array_merge($this->getDefaultAttributes(), $data);
    }

    /**
     * Check if user has permission to create resource.
     *
     * Convenience method that uses the authorization system.
     *
     * @param  mixed  ...$arguments  The action arguments
     * @return bool Whether user has permission
     */
    protected function canCreate(...$arguments): bool
    {
        $ability = $this->getAuthorizationAbility();
        $authArguments = $this->getAuthorizationArguments(...$arguments);

        return Gate::allows($ability, $authArguments);
    }

    /**
     * Ensure user has permission to create resource.
     *
     * Throws authorization exception if user doesn't have permission.
     *
     * @param  mixed  ...$arguments  The action arguments
     *
     * @throws AuthorizationException
     */
    protected function ensureCanCreate(...$arguments): void
    {
        if (! $this->canCreate(...$arguments)) {
            abort(403, 'This action is unauthorized.');
        }
    }

    /**
     * Get resource type name.
     *
     * Extracts resource type from class name (e.g., "CreateTag" -> "tag").
     *
     * @return string Resource type name
     */
    protected function getResourceType(): string
    {
        return $this->getResourceName();
    }

    /**
     * Get full resource identifier.
     *
     * Returns a full identifier like "module.resource" (e.g., "tags.tag").
     *
     * @return string Full resource identifier
     */
    protected function getFullResourceIdentifier(): string
    {
        $module = $this->getModuleName();
        $resource = $this->getResourceType();

        return $module && $resource ? "{$module}.{$resource}" : $resource;
    }
}
