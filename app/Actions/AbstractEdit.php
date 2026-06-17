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
use App\Actions\Concerns\ProvidesEditActionTelemetry;
use App\Actions\Concerns\ProvidesEditResourceHelpers;
use App\Actions\Concerns\AsAuditable;
use App\Actions\Concerns\AsAuthenticated;
use App\Actions\Concerns\AsAuthorized;
use App\Actions\Concerns\AsBroadcast;
use App\Actions\Concerns\AsDependent;
use App\Actions\Concerns\AsEvent;
use App\Actions\Concerns\AsMetrics;
use App\Actions\Concerns\AsRetry;
use App\Actions\Concerns\AsReversible;
use App\Actions\Concerns\AsTracer;
use App\Actions\Concerns\AsTransaction;
use App\Actions\Concerns\AsTransformer;
use App\Actions\Concerns\AsUpdatable;
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
 * Abstract Base Class for Edit/Update Actions
 *
 * This abstract class provides a comprehensive baseline for all edit/update actions
 * in the application. It includes essential concerns, useful optional concerns,
 * and nice-to-have concerns that most update actions will benefit from.
 *
 * Essential Concerns Included:
 * - AsValidated: Input validation
 * - AsTransaction: Database transaction wrapping
 * - AsAuthenticated: Authentication requirement
 * - AsAuthorized: Permission checks (default: 'update')
 * - AsAuditable: Audit trail logging (tracks before/after state)
 * - AsMetrics: Performance tracking
 * - AsDependent: Dependency declaration
 * - AsUpdatable: Change tracking and update metadata
 * - AsReversible: Undo/rollback capability
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
 *     'name' => 'sometimes|string|max:255',
 * ])]
 * class UpdateTag extends AbstractEdit
 * {
 *     public function handle(Tag $tag, array $formData): Tag
 *     {
 *         // Your update logic here
 *         $tag->update($formData);
 *         return $tag->fresh();
 *     }
 * }
 * ```
 *
 * Override Methods:
 * - `handle(...$arguments)` - REQUIRED: Implement your update logic
 * - `getAuthorizationAbility()` - Customize permission check (default: 'update')
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
 * - `shouldTrackChanges()` - Control change tracking (default: true)
 * - `shouldDispatchEvent()` - Control event dispatching (default: false)
 * - `getUpdateEventClass()` - Specify event class to dispatch
 * - `getReversalData()` - Provide data for undo/rollback
 * - `reverse()` - Implement undo logic
 */
#[TransactionAttempts(1)]
#[WatermarkMode('append')]
#[WatermarkEnabled(true)]
#[TransformMode('nested')]
abstract class AbstractEdit
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
    use AsReversible;
    use AsTracer;
    use AsTransaction;
    use AsTransformer;
    use AsUpdatable;
    use AsValidated;
    use AsWatermarked;
    use AsWebhook;
    use ProvidesEditActionTelemetry;
    use ProvidesEditResourceHelpers;

    /**
     * Handle the update logic.
     *
     * This method must be implemented by subclasses to provide
     * the actual update logic. Subclasses can define their own
     * method signature with typed parameters and return types.
     *
     * Example:
     * public function handle(Tag $tag, array $formData): Tag
     * {
     *     $tag->update($formData);
     *     return $tag->fresh();
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
        return 'update';
    }

    /**
     * Get the authorization arguments for this action.
     *
     * Used by AsAuthorized to pass context to permission checks.
     * Default implementation passes all arguments.
     *
     * @param  mixed  ...$arguments  The action arguments
     * @return array<int, mixed> Arguments to pass to the Gate
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
     * @param  array<int, mixed>  $arguments  The action arguments
     * @return array<string, mixed> Custom audit data to merge
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
     * @return array<string, mixed> Custom watermark data to merge
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
     * @param  array<int, mixed>  $arguments  The action arguments
     * @return array<string, mixed> Custom trace attributes
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
        $className = class_basename(static::class);
        $module = $this->getModuleName();

        return $module ? "{$module}.update" : strtolower($className);
    }


    /**
     * Customize transformations for this action.
     *
     * Used by AsTransformer to transform result data structure.
     * Override to customize transformations.
     *
     * @return array<string, mixed> Transformation rules
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
     * @param  array<int, mixed>  $arguments  The action arguments
     * @return array<string, mixed> Webhook payload
     */
    protected function getWebhookPayload($result, array $arguments): array
    {
        return [
            'id' => $result->id ?? null,
            'updated_at' => $result->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Handle webhook success.
     *
     * Called by AsWebhook after successful webhook dispatch.
     * Override to handle webhook success.
     *
     * @param  Response  $response  The HTTP response
     * @param  array<string, mixed>  $payload  The webhook payload
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
     * @param  array<string, mixed>  $payload  The webhook payload
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
     * @return string|array<int, string> Broadcast channel name(s)
     */
    protected function getBroadcastChannel(...$arguments): string|array
    {
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

        return $module && $resource ? "{$module}.{$resource}.updated" : 'resource.updated';
    }


    /**
     * Customize broadcast payload for this action.
     *
     * Used by AsBroadcast to format the payload sent to frontend.
     * Override to customize broadcast payload.
     *
     * @param  mixed  $result  The action result
     * @param  array<int, mixed>  $arguments  The action arguments
     * @return array<string, mixed> Broadcast payload
     */
    protected function getBroadcastPayload($result, array $arguments): array
    {
        return [
            'id' => $result->id ?? null,
            'updated_at' => $result->updated_at?->toIso8601String(),
        ];
    }


    // ============================================================================
    // Utility Methods - Leverage the Power of All Concerns
    // ============================================================================


    // ============================================================================
    // Update-Specific Utility Methods
    // ============================================================================


    /**
     * Check if update should track changes.
     *
     * Used by AsUpdatable to control change tracking.
     * Override to customize change tracking behavior.
     *
     * @return bool Whether to track changes
     */
    protected function shouldTrackChanges(): bool
    {
        return true; // Default: track changes
    }

    /**
     * Check if update should dispatch event.
     *
     * Used by AsUpdatable to control event dispatching.
     * Override to customize event dispatching behavior.
     *
     * @return bool Whether to dispatch event
     */
    protected function shouldDispatchEvent(): bool
    {
        return false; // Default: don't dispatch (override if needed)
    }

    /**
     * Get the event class to dispatch.
     *
     * Used by AsUpdatable to specify which event class to dispatch.
     * Override to customize event class.
     *
     * @return string|null Event class name or null
     */
    protected function getUpdateEventClass(): ?string
    {
        return null; // Override to specify event class
    }
}
