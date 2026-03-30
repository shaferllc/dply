<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Decorates actions when used as scheduled tasks.
 *
 * @example
 * // When an action with AsSchedule is called from the scheduler:
 * $schedule->call(CleanupOldRecords::class);
 *
 * // This decorator wraps the action and calls handle() when invoked
 * // by Laravel's scheduler.
 */
class ScheduleDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function __invoke(): mixed
    {
        // Scheduled tasks typically don't take arguments
        if ($this->hasMethod('handle')) {
            return $this->callMethod('handle');
        }

        // Try asSchedule() method
        if ($this->hasMethod('asSchedule')) {
            return $this->callMethod('asSchedule');
        }

        return null;
    }
}
