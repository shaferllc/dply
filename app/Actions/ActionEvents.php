<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\Event;

/**
 * Action Events System - Lifecycle events for actions.
 *
 * Provides a comprehensive event system for tracking action
 * lifecycle events (before execution, after execution, on failure).
 *
 * Events are automatically dispatched by MetricsDecorator when
 * actions use the AsMetrics trait.
 *
 * @example
 * // Listen to all action events
 * ActionEvents::listen(function ($event, $data) {
 *     \Log::info("Action event: {$event}", $data);
 *     // $data contains: action, arguments, result, exception, timestamp
 * });
 * @example
 * // Listen to specific event
 * ActionEvents::listenTo('before_execution', function ($data) {
 *     \Log::info("Action about to execute: {$data['action']}");
 * });
 *
 * ActionEvents::listenTo('after_execution', function ($data) {
 *     \Log::info("Action completed: {$data['action']}", [
 *         'result' => $data['result'],
 *     ]);
 * });
 *
 * ActionEvents::listenTo('failure', function ($data) {
 *     \Log::error("Action failed: {$data['action']}", [
 *         'exception' => $data['exception'],
 *         'message' => $data['message'],
 *     ]);
 * });
 * @example
 * // Listen to events for specific action
 * ActionEvents::listenToAction(ProcessOrder::class, function ($event, $data) {
 *     if ($event === 'action.failure') {
 *         // Send alert for ProcessOrder failures
 *         \Notification::route('slack', '#alerts')
 *             ->notify(new ActionFailedNotification($data));
 *     }
 * });
 * @example
 * // Track action execution in database
 * ActionEvents::listenTo('after_execution', function ($data) {
 *     \DB::table('action_executions')->insert([
 *         'action' => $data['action'],
 *         'status' => 'success',
 *         'executed_at' => $data['timestamp'],
 *     ]);
 * });
 *
 * ActionEvents::listenTo('failure', function ($data) {
 *     \DB::table('action_executions')->insert([
 *         'action' => $data['action'],
 *         'status' => 'failed',
 *         'exception' => $data['exception'],
 *         'executed_at' => $data['timestamp'],
 *     ]);
 * });
 */
class ActionEvents
{
    /**
     * Dispatch a before execution event.
     *
     * @param  string  $actionClass  Action class name
     * @param  array  $arguments  Arguments being passed to the action
     */
    public static function beforeExecution(string $actionClass, array $arguments): void
    {
        Event::dispatch('action.before_execution', [
            'action' => $actionClass,
            'arguments' => $arguments,
            'timestamp' => now(),
        ]);
    }

    /**
     * Dispatch an after execution event.
     *
     * @param  string  $actionClass  Action class name
     * @param  mixed  $result  Result from the action
     * @param  array  $arguments  Arguments that were passed
     */
    public static function afterExecution(string $actionClass, mixed $result, array $arguments): void
    {
        Event::dispatch('action.after_execution', [
            'action' => $actionClass,
            'result' => $result,
            'arguments' => $arguments,
            'timestamp' => now(),
        ]);
    }

    /**
     * Dispatch a failure event.
     *
     * @param  string  $actionClass  Action class name
     * @param  \Throwable  $exception  Exception that was thrown
     * @param  array  $arguments  Arguments that were passed
     */
    public static function onFailure(string $actionClass, \Throwable $exception, array $arguments): void
    {
        Event::dispatch('action.failure', [
            'action' => $actionClass,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'arguments' => $arguments,
            'timestamp' => now(),
        ]);
    }

    /**
     * Listen to all action events.
     *
     * @param  callable  $callback  Callback to execute on any action event
     */
    public static function listen(callable $callback): void
    {
        Event::listen('action.*', $callback);
    }

    /**
     * Listen to a specific action event.
     *
     * @param  string  $event  Event name (before_execution, after_execution, failure)
     * @param  callable  $callback  Callback to execute
     */
    public static function listenTo(string $event, callable $callback): void
    {
        Event::listen("action.{$event}", $callback);
    }

    /**
     * Listen to events for a specific action.
     *
     * @param  string  $actionClass  Action class name
     * @param  callable  $callback  Callback to execute
     */
    public static function listenToAction(string $actionClass, callable $callback): void
    {
        Event::listen('action.*', function ($event, $data) use ($actionClass, $callback) {
            if ($data['action'] === $actionClass) {
                $callback($event, $data);
            }
        });
    }
}
