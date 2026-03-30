<?php

namespace App\Actions\Decorators;

use App\Actions\Attributes\TraceEnabled;
use App\Actions\Attributes\TraceName;
use App\Actions\Concerns\DecorateActions;
use App\Actions\Tracers\ActionTracer;

/**
 * Tracer Decorator
 *
 * Automatically adds distributed tracing support to actions.
 * This decorator creates trace spans for action execution, allowing
 * you to track performance, debug issues, and monitor distributed systems.
 *
 * Features:
 * - Automatic span creation for action execution
 * - Trace ID and Span ID generation
 * - Custom trace names and attributes
 * - Success/failure tracking
 * - Exception recording
 * - Trace metadata in results
 * - Configurable via config or methods
 *
 * How it works:
 * 1. When an action uses AsTracer, TracerDesignPattern recognizes it
 * 2. ActionManager wraps the action with TracerDecorator
 * 3. When handle() is called, the decorator:
 *    - Generates trace ID and span ID
 *    - Gets trace name and attributes from action
 *    - Starts a trace span
 *    - Executes the action's handle()
 *    - Ends the span (success or failure)
 *    - Adds trace metadata to the result
 *
 * Trace Metadata:
 * The result will always include a `_trace` property with:
 * - `trace_id`: Unique trace identifier (always generated)
 * - `span_id`: Unique span identifier (always generated)
 * - `name`: The trace/span name
 * - `success`: Whether the operation succeeded
 * - `enabled`: Whether tracing was active (spans were recorded)
 * - `attributes`: Custom trace attributes from the action
 *
 * Example:
 * $result = CreateTag::run($team, ['name' => 'New Tag']);
 * // $result->_trace = [
 * //     'trace_id' => 'abc123...',
 * //     'span_id' => 'def456...',
 * //     'name' => 'tags.create',
 * //     'success' => true,
 * //     'enabled' => true,
 * //     'attributes' => [
 * //         'team_id' => 1,
 * //         'team_name' => 'My Team',
 * //         'tag_name' => 'New Tag',
 * //         'version' => 'v2',
 * //     ],
 * // ];
 */
class TracerDecorator
{
    use DecorateActions;

    protected ActionTracer $tracer;

    public function __construct($action)
    {
        $this->setAction($action);
        $this->tracer = app(ActionTracer::class);
    }

    /**
     * Execute the action with tracing.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function handle(...$arguments)
    {
        $shouldTrace = $this->shouldTrace();
        $traceId = $this->tracer->generateTraceId();
        $spanId = $this->tracer->generateSpanId();
        $traceName = $this->getTraceName();
        $attributes = $this->getTraceAttributes($arguments);

        // Start the trace span if tracing is enabled
        if ($shouldTrace) {
            $this->tracer->startSpan($traceName, $traceId, $spanId, $attributes);
        }

        try {
            $result = $this->action->handle(...$arguments);

            // End span with success if tracing is enabled
            if ($shouldTrace) {
                $this->tracer->endSpan($traceId, $spanId, true);
            }

            // Always add trace metadata to result (even if tracing is disabled)
            return $this->addTraceMetadata($result, $traceId, $spanId, $traceName, true, $shouldTrace, $attributes);
        } catch (\Throwable $e) {
            // End span with failure if tracing is enabled
            if ($shouldTrace) {
                $this->tracer->endSpan($traceId, $spanId, false, $e);
            }

            // Note: If exception is thrown, result won't have metadata, but span is recorded if enabled

            throw $e;
        }
    }

    /**
     * Make the decorator callable.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    /**
     * Determine if tracing should be enabled.
     *
     * Checks for:
     * 1. #[TraceEnabled] attribute on the action
     * 2. shouldTrace() method on the action
     * 3. shouldTrace property on the action
     * 4. config('actions.tracing.enabled', false)
     */
    protected function shouldTrace(): bool
    {
        // Check for attribute first
        $enabled = $this->getAttributeValue(TraceEnabled::class);
        if ($enabled !== null) {
            return (bool) $enabled;
        }

        // Fall back to method or property
        $fromAction = $this->fromActionMethodOrProperty(
            'shouldTrace',
            'shouldTrace',
            null
        );

        if ($fromAction !== null) {
            return (bool) $fromAction;
        }

        return config('actions.tracing.enabled', false);
    }

    /**
     * Get the trace name.
     *
     * Checks for:
     * 1. #[TraceName] attribute on the action
     * 2. getTraceName() method on the action
     * 3. traceName property on the action
     * 4. Defaults to action class name
     */
    protected function getTraceName(): string
    {
        // Check for attribute first
        $name = $this->getAttributeValue(TraceName::class);
        if ($name !== null) {
            return (string) $name;
        }

        // Fall back to method or property
        $name = $this->fromActionMethodOrProperty(
            'getTraceName',
            'traceName',
            null
        );

        if ($name !== null) {
            return (string) $name;
        }

        // Get the original action class name
        $originalAction = $this->getOriginalAction();

        return get_class($originalAction);
    }

    /**
     * Get trace attributes.
     *
     * Checks for:
     * 1. getTraceAttributes() method on the original action (receives arguments)
     * 2. traceAttributes property on the original action
     * 3. Defaults to empty array
     *
     * @param  array  $arguments  Action arguments
     */
    protected function getTraceAttributes(array $arguments): array
    {
        // Get the original action to call methods on it directly
        $originalAction = $this->getOriginalAction();

        if (method_exists($originalAction, 'getTraceAttributes')) {
            try {
                $attributes = $originalAction->getTraceAttributes($arguments);

                return is_array($attributes) ? $attributes : [];
            } catch (\Throwable $e) {
                // If method fails, return empty array
                return [];
            }
        }

        if (property_exists($originalAction, 'traceAttributes')) {
            return $originalAction->traceAttributes ?? [];
        }

        return [];
    }

    /**
     * Get attribute value from the original action.
     *
     * @param  string  $attributeClass  The attribute class name
     * @return mixed The attribute value, or null if not found
     */
    protected function getAttributeValue(string $attributeClass): mixed
    {
        $originalAction = $this->getOriginalAction();

        try {
            $reflection = new \ReflectionClass($originalAction);
            $attributes = $reflection->getAttributes($attributeClass);

            if (! empty($attributes)) {
                $attribute = $attributes[0]->newInstance();
                if ($attribute instanceof TraceName) {
                    return $attribute->name;
                }
                if ($attribute instanceof TraceEnabled) {
                    return $attribute->enabled;
                }
            }
        } catch (\ReflectionException $e) {
            // Attribute not found or can't be read
        }

        return null;
    }

    /**
     * Get the original action (unwrap decorators).
     *
     * @return mixed
     */
    protected function getOriginalAction()
    {
        $action = $this->action;

        // Unwrap decorators to get the original action
        while (str_starts_with(get_class($action), 'App\\Actions\\Decorators\\')) {
            $reflection = new \ReflectionClass($action);
            if ($reflection->hasProperty('action')) {
                $property = $reflection->getProperty('action');
                $property->setAccessible(true);
                $action = $property->getValue($action);
            } else {
                break;
            }
        }

        return $action;
    }

    /**
     * Add trace metadata to the result.
     *
     * Adds a `_trace` property to the result indicating:
     * - Trace ID
     * - Span ID
     * - Trace name
     * - Success status
     * - Enabled status (whether tracing was active)
     * - Attributes (custom trace attributes)
     *
     * @param  mixed  $result  The action result
     * @param  string  $traceId  The trace ID
     * @param  string  $spanId  The span ID
     * @param  string  $traceName  The trace name
     * @param  bool  $success  Whether the operation succeeded
     * @param  bool  $enabled  Whether tracing was enabled
     * @param  array  $attributes  Custom trace attributes
     * @return mixed The result with trace metadata added
     */
    protected function addTraceMetadata(mixed $result, string $traceId, string $spanId, string $traceName, bool $success, bool $enabled = true, array $attributes = []): mixed
    {
        $metadata = [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'name' => $traceName,
            'success' => $success,
            'enabled' => $enabled,
            'attributes' => $attributes,
        ];

        if (is_array($result)) {
            $result['_trace'] = $metadata;

            return $result;
        }

        if (is_object($result)) {
            // Try to add trace metadata as property (preserves object type)
            try {
                $reflection = new \ReflectionClass($result);
                if ($reflection->hasProperty('_trace')) {
                    $property = $reflection->getProperty('_trace');
                    $property->setAccessible(true);
                    $property->setValue($result, $metadata);
                } else {
                    // Property doesn't exist, use dynamic property (works for Eloquent models)
                    $result->_trace = $metadata;
                }
            } catch (\ReflectionException $e) {
                // Fallback: try direct assignment
                $result->_trace = $metadata;
            }

            return $result;
        }

        // For other types, wrap in array with metadata
        return [
            'data' => $result,
            '_trace' => $metadata,
        ];
    }
}
