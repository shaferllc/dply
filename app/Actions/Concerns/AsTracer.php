<?php

namespace App\Actions\Concerns;

use App\Actions\Attributes\TraceEnabled;
use App\Actions\Attributes\TraceName;
use App\Actions\Decorators\TracerDecorator;
use App\Actions\DesignPatterns\TracerDesignPattern;
use App\Actions\Tracers\ActionTracer;

/**
 * Automatically adds distributed tracing support to actions.
 *
 * Uses the decorator pattern to automatically wrap actions and add
 * distributed tracing capabilities. The TracerDecorator intercepts
 * handle() calls and creates trace spans for execution tracking.
 *
 * How it works:
 * 1. When an action uses AsTracer, TracerDesignPattern recognizes it
 * 2. ActionManager wraps the action with TracerDecorator
 * 3. When handle() is called, the decorator:
 *    - Generates trace ID and span ID
 *    - Gets trace name and attributes from action
 *    - Starts a trace span via ActionTracer
 *    - Executes the action's handle()
 *    - Ends the span (success or failure)
 *    - Adds trace metadata to the result
 *
 * Benefits:
 * - Distributed tracing for debugging and monitoring
 * - Performance tracking and bottleneck identification
 * - Exception tracking and error analysis
 * - Trace metadata in results for correlation
 * - Configurable via config or methods
 * - Seamless integration with other decorators
 *
 * Configuration:
 * Enable tracing globally via config:
 * config(['actions.tracing.enabled' => true]);
 * config(['actions.tracing.log_enabled' => true]); // Log traces to Laravel logs
 *
 * @example
 * // ============================================
 * // Example 1: Basic Usage (Default Behavior)
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsTracer;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Action logic - automatically traced
 *         $order->process();
 *         return $order;
 *     }
 * }
 *
 * // Usage:
 * $order = ProcessOrder::run($order);
 * // Trace metadata: $order->_trace = [
 * //     'trace_id' => 'abc123...',
 * //     'span_id' => 'def456...',
 * //     'name' => 'App\\Actions\\ProcessOrder',
 * //     'success' => true,
 * //     'enabled' => true,  // Whether tracing spans were recorded
 * //     'attributes' => [],
 * // ]
 * @example
 * // ============================================
 * // Example 2: Custom Trace Name (Using Attribute)
 * // ============================================
 * use App\Actions\Attributes\TraceName;
 *
 * #[TraceName('order.process')]
 * class ProcessOrder extends Actions
 * {
 *     use AsTracer;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->process();
 *         return $order;
 *     }
 * }
 *
 * // Usage:
 * $order = ProcessOrder::run($order);
 * // $order->_trace['name'] = 'order.process'
 * @example
 * // ============================================
 * // Example 3: Custom Trace Name (Using Method)
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsTracer;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->process();
 *         return $order;
 *     }
 *
 *     public function getTraceName(): string
 *     {
 *         return 'order.process';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Custom Trace Attributes
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsTracer;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->process();
 *         return $order;
 *     }
 *
 *     public function getTraceAttributes(array $arguments): array
 *     {
 *         $order = $arguments[0] ?? null;
 *
 *         return [
 *             'order_id' => $order?->id,
 *             'user_id' => $order?->user_id,
 *             'amount' => $order?->total,
 *             'status' => $order?->status,
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $order = ProcessOrder::run($order);
 * // $order->_trace['attributes'] = [
 * //     'order_id' => 123,
 * //     'user_id' => 456,
 * //     'amount' => 99.99,
 * //     'status' => 'processing',
 * // ]
 * @example
 * // ============================================
 * // Example 5: Disable Tracing (Using Attribute)
 * // ============================================
 * use App\Actions\Attributes\TraceEnabled;
 *
 * #[TraceEnabled(false)] // Disable tracing for this action
 * class ProcessOrder extends Actions
 * {
 *     use AsTracer;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->process();
 *         return $order;
 *     }
 * }
 *
 * // Usage:
 * $order = ProcessOrder::run($order);
 * // Trace metadata still added, but spans not recorded
 * // $order->_trace['enabled'] = false
 * @example
 * // ============================================
 * // Example 6: Conditional Tracing (Using Method)
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsTracer;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->process();
 *         return $order;
 *     }
 *
 *     public function shouldTrace(): bool
 *     {
 *         // Only trace in production or when explicitly enabled
 *         return app()->environment('production') || config('actions.tracing.enabled', false);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 7: Real-World Usage (from Tags\Actions\Create)
 * // ============================================
 * use App\Actions\Attributes\TraceName;
 *
 * #[TraceName('tags.create')]
 * class CreateTag extends Actions
 * {
 *     use AsTracer;
 *
 *     public function handle(Team $team, array $formData): Tag
 *     {
 *         $tag = Tag::create($formData);
 *         $team->tags()->attach($tag);
 *         return $tag;
 *     }
 *
 *     public function getTraceAttributes(array $arguments): array
 *     {
 *         $team = $arguments[0] ?? null;
 *         $formData = $arguments[1] ?? [];
 *
 *         return [
 *             'team_id' => $team?->id,
 *             'team_name' => $team?->name,
 *             'tag_name' => $formData['name'] ?? null,
 *             'tag_type' => $formData['type'] ?? null,
 *             'version' => $this->getVersion(),
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $tag = CreateTag::run($team, ['name' => 'New Tag']);
 * // $tag->_trace = [
 * //     'trace_id' => 'abc123...',
 * //     'span_id' => 'def456...',
 * //     'name' => 'tags.create',
 * //     'success' => true,
 * //     'enabled' => true,
 * //     'attributes' => [
 * //         'team_id' => 1,
 * //         'team_name' => 'My Team',
 * //         'tag_name' => 'New Tag',
 * //         'tag_type' => null,
 * //         'version' => 'v2',
 * //     ],
 * // ]
 * @example
 * // ============================================
 * // Example 8: Accessing Trace Information
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsTracer;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->process();
 *         return $order;
 *     }
 * }
 *
 * // Usage:
 * $order = ProcessOrder::run($order);
 *
 * // Access trace information:
 * $traceId = $order->_trace['trace_id'];
 * $spanId = $order->_trace['span_id'];
 * $traceName = $order->_trace['name'];
 * $attributes = $order->_trace['attributes'];
 * $success = $order->_trace['success'];
 * $enabled = $order->_trace['enabled'];
 *
 * // Use trace ID for correlation across services:
 * // Pass $traceId to external API calls, log messages, etc.
 * @example
 * // ============================================
 * // Example 9: Error Tracking
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsTracer;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // If an exception is thrown, the span is marked as failed
 *         if ($order->total > 1000) {
 *             throw new \Exception('Order amount too high');
 *         }
 *
 *         $order->process();
 *         return $order;
 *     }
 * }
 *
 * // Usage:
 * try {
 *     $order = ProcessOrder::run($order);
 *     // Span recorded with success = true
 * } catch (\Exception $e) {
 *     // Span recorded with success = false and exception details
 *     // Trace metadata not added to result (exception thrown)
 * }
 * @example
 * // ============================================
 * // Example 10: Dynamic Trace Attributes
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsTracer;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->process();
 *         return $order;
 *     }
 *
 *     public function getTraceAttributes(array $arguments): array
 *     {
 *         $order = $arguments[0] ?? null;
 *         $attributes = [
 *             'order_id' => $order?->id,
 *             'user_id' => $order?->user_id,
 *         ];
 *
 *         // Add version-specific attributes
 *         if ($this->getVersion() === 'v2') {
 *             $attributes['api_version'] = 'v2';
 *             $attributes['features'] = ['new_checkout', 'enhanced_validation'];
 *         }
 *
 *         // Add environment-specific attributes
 *         $attributes['environment'] = app()->environment();
 *         $attributes['request_id'] = request()->header('X-Request-ID');
 *
 *         return $attributes;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 11: Global Configuration
 * // ============================================
 * // Enable tracing globally in a service provider or config file:
 * config(['actions.tracing.enabled' => true]);
 * config(['actions.tracing.log_enabled' => true]); // Log traces to Laravel logs
 *
 * // All actions using AsTracer will now be traced
 * // Individual actions can still override with shouldTrace() or #[TraceEnabled]
 * @example
 * // ============================================
 * // Default Behavior
 * // ============================================
 * // Default trace name: Action class name (e.g., 'App\\Actions\\ProcessOrder')
 * // Default enabled: config('actions.tracing.enabled', false)
 * // Default attributes: []
 * //
 * // Trace metadata is ALWAYS added to results (even if tracing is disabled):
 * // - For objects: $result->_trace property
 * // - For arrays: $result['_trace'] key
 * // - For other types: Wrapped in array with 'data' and '_trace' keys
 * //
 * // Trace metadata includes:
 * // - 'trace_id': Unique trace identifier (always generated)
 * // - 'span_id': Unique span identifier (always generated)
 * // - 'name': The trace/span name
 * // - 'success': Whether the operation succeeded
 * // - 'enabled': Whether tracing was active (spans were recorded)
 * // - 'attributes': Custom trace attributes from the action
 * //
 * // Priority order for configuration:
 * // 1. PHP attributes (#[TraceName], #[TraceEnabled])
 * // 2. Methods (getTraceName(), shouldTrace(), getTraceAttributes())
 * // 3. Properties (traceName, shouldTrace, traceAttributes)
 * // 4. Config values (actions.tracing.enabled)
 * // 5. Default values
 * //
 * // Trace IDs and Span IDs are always generated, even if tracing is disabled.
 * // This allows correlation across services even when spans aren't recorded.
 *
 * @see TracerDecorator
 * @see TracerDesignPattern
 * @see ActionTracer
 * @see TraceName
 * @see TraceEnabled
 */
trait AsTracer
{
    //
}
