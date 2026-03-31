<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsSchedule;
use App\Actions\Decorators\ScheduleDecorator;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Recognizes when actions are used as scheduled tasks.
 *
 * @example
 * // Action class:
 * class CleanupOldRecords extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         Record::where('created_at', '<', now()->subDays(30))->delete();
 *     }
 *
 *     public function getScheduleFrequency(): string
 *     {
 *         return 'daily';
 *     }
 * }
 *
 * // Register in app/Console/Kernel.php:
 * protected function schedule(Schedule $schedule): void
 * {
 *     CleanupOldRecords::schedule($schedule);
 * }
 *
 * // The design pattern automatically recognizes when the action
 * // is called from the scheduler and decorates it appropriately.
 */
class ScheduleDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsSchedule::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return $frame->instanceOf(Schedule::class)
            || $frame->matches(Schedule::class, 'call')
            || $frame->matches(Schedule::class, 'command');
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(ScheduleDecorator::class, ['action' => $instance]);
    }
}
