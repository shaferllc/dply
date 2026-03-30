<?php

namespace App\Actions\Concerns;

/**
 * Provides execution context for actions.
 *
 * This trait does NOT need to be a decorator because it:
 * - Provides utility methods, not execution interception
 * - Adds helper functionality directly to actions
 * - Doesn't wrap or modify action execution flow
 *
 * Decorators are for cross-cutting concerns that intercept execution
 * (like DebounceDecorator, ThrottleDecorator, etc.)
 *
 * @example
 * // Basic usage - access context in your action:
 * class ProcessRequest extends Actions
 * {
 *     use AsContext;
 *
 *     public function handle(): array
 *     {
 *         $context = $this->getContext();
 *
 *         return [
 *             'user' => $context->user?->id,
 *             'ip' => $context->ip,
 *             'user_agent' => $context->userAgent,
 *             'timestamp' => $context->timestamp,
 *         ];
 *     }
 * }
 * @example
 * // Customize context data by overriding getContextData():
 * class LogUserActivity extends Actions
 * {
 *     use AsContext;
 *
 *     public function handle(User $user): void
 *     {
 *         $context = $this->getContext();
 *
 *         ActivityLog::create([
 *             'user_id' => $context->user?->id,
 *             'action' => 'viewed_profile',
 *             'ip_address' => $context->ip,
 *             'metadata' => $context->custom_metadata,
 *         ]);
 *     }
 *
 *     protected function getContextData(): array
 *     {
 *         return [
 *             'custom_metadata' => [
 *                 'session_id' => session()->getId(),
 *                 'referrer' => request()->header('Referer'),
 *             ],
 *         ];
 *     }
 * }
 * @example
 * // Use setContext() to manually set context (useful for testing or CLI):
 * class GenerateReport extends Actions
 * {
 *     use AsContext;
 *
 *     public function handle(): Report
 *     {
 *         $context = $this->getContext();
 *
 *         return Report::create([
 *             'generated_by' => $context->user?->id,
 *             'generated_at' => $context->timestamp,
 *             'request_id' => $context->request_id,
 *         ]);
 *     }
 * }
 *
 * // In tests or CLI:
 * $action = new GenerateReport();
 * $action->setContext(['user' => $testUser, 'ip' => '127.0.0.1']);
 * $action->handle();
 * @example
 * // Use context for audit logging:
 * class UpdateUserProfile extends Actions
 * {
 *     use AsContext;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $context = $this->getContext();
 *
 *         $user->update($data);
 *
 *         AuditLog::create([
 *             'user_id' => $context->user?->id,
 *             'action' => 'profile_updated',
 *             'target_user_id' => $user->id,
 *             'ip_address' => $context->ip,
 *             'user_agent' => $context->userAgent,
 *             'request_id' => $context->request_id,
 *         ]);
 *
 *         return $user;
 *     }
 * }
 * @example
 * // Use context for rate limiting or tracking:
 * class SendNotification extends Actions
 * {
 *     use AsContext;
 *
 *     public function handle(User $user, string $message): void
 *     {
 *         $context = $this->getContext();
 *
 *         // Track notification source
 *         $notification = Notification::create([
 *             'user_id' => $user->id,
 *             'message' => $message,
 *             'sent_by' => $context->user?->id,
 *             'sent_from_ip' => $context->ip,
 *         ]);
 *
 *         // Rate limit based on IP
 *         RateLimiter::attempt(
 *             "notifications:{$context->ip}",
 *             $perMinute = 10,
 *             fn() => $user->notify($notification)
 *         );
 *     }
 * }
 * @example
 * // Use context in API actions for request tracking:
 * class ProcessApiRequest extends Actions
 * {
 *     use AsContext;
 *
 *     public function handle(array $data): array
 *     {
 *         $context = $this->getContext();
 *
 *         // Log API request with full context
 *         ApiRequestLog::create([
 *             'user_id' => $context->user?->id,
 *             'ip' => $context->ip,
 *             'user_agent' => $context->userAgent,
 *             'request_id' => $context->request_id ?? Str::uuid(),
 *             'data' => $data,
 *         ]);
 *
 *         return $this->process($data);
 *     }
 *
 *     protected function getContextData(): array
 *     {
 *         return [
 *             'api_version' => request()->header('X-API-Version'),
 *             'client_id' => request()->header('X-Client-ID'),
 *         ];
 *     }
 * }
 * @example
 * // Use context for error reporting with full context:
 * class ProcessPayment extends Actions
 * {
 *     use AsContext;
 *
 *     public function handle(Order $order, PaymentMethod $method): Payment
 *     {
 *         try {
 *             return $this->charge($order, $method);
 *         } catch (\Exception $e) {
 *             $context = $this->getContext();
 *
 *             // Report error with full context
 *             ErrorReporter::report($e, [
 *                 'user_id' => $context->user?->id,
 *                 'order_id' => $order->id,
 *                 'ip' => $context->ip,
 *                 'request_id' => $context->request_id,
 *             ]);
 *
 *             throw $e;
 *         }
 *     }
 * }
 */
trait AsContext
{
    protected ?\stdClass $context = null;

    /**
     * Get the execution context.
     *
     * Returns a stdClass object with:
     * - user: The authenticated user (or null)
     * - ip: The request IP address
     * - userAgent: The request user agent
     * - timestamp: The current timestamp
     * - request_id: The X-Request-ID header (if present)
     * - Any additional data from getContextData()
     *
     * @return \stdClass{user: \Illuminate\Contracts\Auth\Authenticatable|null, ip: string|null, user_agent: string|null, timestamp: \Illuminate\Support\Carbon, request_id: string|null, ...}
     */
    protected function getContext(): \stdClass
    {
        if ($this->context === null) {
            $this->context = (object) array_merge([
                'user' => auth()->user(),
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'timestamp' => now(),
                'request_id' => request()?->header('X-Request-ID'),
            ], $this->getContextData());
        }

        return $this->context;
    }

    /**
     * Get additional context data.
     *
     * Override this method in your action to add custom context data.
     * The returned array will be merged with the default context.
     *
     * @return array<string, mixed>
     */
    protected function getContextData(): array
    {
        // Check if the class using this trait has overridden getContextData
        $reflection = new \ReflectionClass($this);

        if ($reflection->hasMethod('getContextData')) {
            $method = $reflection->getMethod('getContextData');
            // Only call if it's not from this trait (AsContext)
            $traitName = __TRAIT__;
            if ($method->getDeclaringClass()->getName() !== $traitName) {
                return $method->invoke($this);
            }
        }

        return [];
    }

    /**
     * Manually set context data.
     *
     * Useful for testing or when running actions outside of HTTP requests.
     * Merges with existing context data.
     *
     * @param  array<string, mixed>  $data
     * @return $this
     */
    public function setContext(array $data): self
    {
        $currentContext = $this->context ? (array) $this->context : [];
        $this->context = (object) array_merge($currentContext, $data);

        return $this;
    }
}
