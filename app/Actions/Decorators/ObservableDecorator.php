<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Event;

/**
 * Observable Decorator
 *
 * Automatically fires events for action execution lifecycle (started, completed, failed).
 * This decorator intercepts handle() calls and fires events for monitoring, debugging,
 * and logging purposes.
 *
 * Features:
 * - Automatic event firing for action lifecycle
 * - Execution duration tracking
 * - Exception tracking
 * - Configurable event firing
 * - Works with Laravel's Event system
 * - Performance monitoring
 *
 * How it works:
 * 1. When an action uses AsObservable, ObservableDesignPattern recognizes it
 * 2. ActionManager wraps the action with ObservableDecorator
 * 3. When handle() is called, the decorator:
 *    - Fires 'action.started' event with action class and arguments
 *    - Records start time
 *    - Executes the action
 *    - Fires 'action.completed' event with result and duration
 *    - On exception, fires 'action.failed' event with exception and duration
 *    - Returns the result (or re-throws exception)
 *
 * Events Fired:
 * - `action.started`: When action execution begins
 *   - Data: ['action' => class name, 'arguments' => array]
 * - `action.completed`: When action completes successfully
 *   - Data: ['action' => class name, 'result' => mixed, 'duration' => float]
 * - `action.failed`: When action throws an exception
 *   - Data: ['action' => class name, 'exception' => Throwable, 'duration' => float]
 */
class ObservableDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with event firing.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \Throwable
     */
    public function handle(...$arguments)
    {
        if (! $this->shouldFireEvents()) {
            return $this->action->handle(...$arguments);
        }

        $this->fireEvent('action.started', [
            'action' => get_class($this->action),
            'arguments' => $arguments,
        ]);

        $startTime = microtime(true);

        try {
            $result = $this->action->handle(...$arguments);

            $duration = microtime(true) - $startTime;

            $this->fireEvent('action.completed', [
                'action' => get_class($this->action),
                'result' => $result,
                'duration' => $duration,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;

            $this->fireEvent('action.failed', [
                'action' => get_class($this->action),
                'exception' => $e,
                'duration' => $duration,
            ]);

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
     * Fire an event if events should be fired.
     */
    protected function fireEvent(string $event, array $data): void
    {
        if ($this->shouldFireEvents()) {
            Event::dispatch($event, $data);
        }
    }

    /**
     * Check if events should be fired.
     */
    protected function shouldFireEvents(): bool
    {
        return $this->fromActionMethodOrProperty('shouldFireEvents', 'shouldFireEvents', function () {
            return config('actions.observable.enabled', true);
        });
    }
}
