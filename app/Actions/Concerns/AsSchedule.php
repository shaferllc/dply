<?php

namespace App\Actions\Concerns;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Allows actions to be scheduled to run automatically.
 *
 * Provides scheduling capabilities for actions, allowing them to run
 * automatically at specified intervals using Laravel's task scheduler.
 * Works with both the static schedule() method for registration and
 * automatic decoration when called from the scheduler.
 *
 * How it works:
 * - Provides static `schedule()` method for easy registration
 * - Automatically decorated when called from Laravel's scheduler
 * - Supports frequency-based scheduling (hourly, daily, etc.)
 * - Supports cron expression scheduling
 * - Allows custom schedule configuration
 * - Integrates with ScheduleDecorator for execution wrapping
 *
 * Benefits:
 * - Easy action registration in scheduler
 * - Automatic decoration when scheduled
 * - Flexible scheduling options
 * - Custom schedule configuration
 * - Works with Laravel's scheduler features
 *
 * Note: This trait works WITH a decorator (ScheduleDecorator) that
 * automatically wraps actions when called from the scheduler. The trait
 * provides the registration method, while the decorator handles execution.
 * This hybrid approach gives you both convenience and automatic decoration.
 *
 * Laravel 12 Scheduling:
 * Register scheduled actions in bootstrap/app.php using ->withSchedule():
 *
 * @example
 * // Basic usage - daily cleanup:
 * class CleanupOldRecords extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Delete records older than 30 days
 *         Record::where('created_at', '<', now()->subDays(30))->delete();
 *     }
 *
 *     public function getScheduleFrequency(): string
 *     {
 *         return 'daily'; // Run once per day
 *     }
 * }
 *
 * // Register in bootstrap/app.php:
 * ->withSchedule(function (Schedule $schedule) {
 *     CleanupOldRecords::schedule($schedule);
 * })
 * @example
 * // Using cron expression for precise timing:
 * class GenerateReports extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Generate daily reports
 *         Report::generateDaily();
 *     }
 *
 *     public function getScheduleExpression(): string
 *     {
 *         return '0 2 * * *'; // Run at 2 AM every day
 *     }
 *
 *     public function configureSchedule($scheduled): void
 *     {
 *         $scheduled->withoutOverlapping()
 *             ->onOneServer()
 *             ->runInBackground();
 *     }
 * }
 *
 * // Register in bootstrap/app.php:
 * ->withSchedule(function (Schedule $schedule) {
 *     GenerateReports::schedule($schedule);
 * })
 * @example
 * // Multiple frequency options:
 * class SendReminders extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Send reminder emails
 *         User::sendReminders();
 *     }
 *
 *     // Available frequencies: everyMinute, hourly, daily, weekly, monthly, etc.
 *     public function getScheduleFrequency(): string
 *     {
 *         return 'hourly'; // Run every hour
 *     }
 * }
 *
 * // Register in bootstrap/app.php:
 * ->withSchedule(function (Schedule $schedule) {
 *     SendReminders::schedule($schedule);
 * })
 * @example
 * // Advanced schedule configuration:
 * class ProcessPayments extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Process pending payments
 *         Payment::processPending();
 *     }
 *
 *     public function getScheduleExpression(): string
 *     {
 *         // Every 5 minutes
 *         return '0,5,10,15,20,25,30,35,40,45,50,55 * * * *';
 *     }
 *
 *     public function configureSchedule($scheduled): void
 *     {
 *         $scheduled
 *             ->withoutOverlapping()      // Prevent concurrent runs
 *             ->onOneServer()             // Run on single server (multi-server)
 *             ->runInBackground()         // Don't block scheduler
 *             ->appendOutputTo(storage_path('logs/payments.log'))
 *             ->emailOutputOnFailure('admin@example.com');
 *     }
 * }
 *
 * // Register in bootstrap/app.php:
 * ->withSchedule(function (Schedule $schedule) {
 *     ProcessPayments::schedule($schedule);
 * })
 * @example
 * // Scheduling with timezone:
 * class SendDailyDigest extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Send daily email digest
 *         Newsletter::sendDailyDigest();
 *     }
 *
 *     public function getScheduleFrequency(): string
 *     {
 *         return 'daily';
 *     }
 *
 *     public function configureSchedule($scheduled): void
 *     {
 *         $scheduled
 *             ->dailyAt('09:00')          // Run at 9 AM
 *             ->timezone('America/New_York') // Use specific timezone
 *             ->withoutOverlapping();
 *     }
 * }
 *
 * // Register in bootstrap/app.php:
 * ->withSchedule(function (Schedule $schedule) {
 *     SendDailyDigest::schedule($schedule);
 * })
 * @example
 * // Conditional scheduling (only on weekdays):
 * class GenerateWeeklyReport extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Generate weekly report
 *         Report::generateWeekly();
 *     }
 *
 *     public function getScheduleFrequency(): string
 *     {
 *         return 'weekly';
 *     }
 *
 *     public function configureSchedule($scheduled): void
 *     {
 *         $scheduled
 *             ->weeklyOn(1, '8:00')       // Monday at 8 AM
 *             ->withoutOverlapping()
 *             ->when(function () {
 *                 // Only run on first Monday of month
 *                 return now()->day <= 7;
 *             });
 *     }
 * }
 *
 * // Register in bootstrap/app.php:
 * ->withSchedule(function (Schedule $schedule) {
 *     GenerateWeeklyReport::schedule($schedule);
 * })
 * @example
 * // Multiple scheduled actions:
 * class DatabaseBackup extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Backup database
 *         DB::backup();
 *     }
 *
 *     public function getScheduleExpression(): string
 *     {
 *         return '0 3 * * *'; // 3 AM daily
 *     }
 * }
 *
 * class CleanupLogs extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Clean old log files
 *         Log::cleanup();
 *     }
 *
 *     public function getScheduleFrequency(): string
 *     {
 *         return 'weekly';
 *     }
 * }
 *
 * // Register all in bootstrap/app.php:
 * ->withSchedule(function (Schedule $schedule) {
 *     DatabaseBackup::schedule($schedule);
 *     CleanupLogs::schedule($schedule);
 *     CleanupOldRecords::schedule($schedule);
 *     GenerateReports::schedule($schedule);
 * })
 * @example
 * // Scheduling with environment conditions:
 * class SyncExternalData extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Sync data from external API
 *         ExternalAPI::sync();
 *     }
 *
 *     public function getScheduleFrequency(): string
 *     {
 *         return 'hourly';
 *     }
 *
 *     public function configureSchedule($scheduled): void
 *     {
 *         $scheduled
 *             ->withoutOverlapping()
 *             ->onOneServer()
 *             ->when(function () {
 *                 // Only run in production
 *                 return app()->environment('production');
 *             })
 *             ->skip(function () {
 *                 // Skip during maintenance
 *                 return app()->isDownForMaintenance();
 *             });
 *     }
 * }
 *
 * // Register in bootstrap/app.php:
 * ->withSchedule(function (Schedule $schedule) {
 *     SyncExternalData::schedule($schedule);
 * })
 * @example
 * // Scheduling with notifications on failure:
 * class ProcessQueue extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Process queued jobs
 *         Queue::process();
 *     }
 *
 *     public function getScheduleFrequency(): string
 *     {
 *         return 'everyMinute';
 *     }
 *
 *     public function configureSchedule($scheduled): void
 *     {
 *         $scheduled
 *             ->withoutOverlapping()
 *             ->appendOutputTo(storage_path('logs/queue.log'))
 *             ->emailOutputOnFailure('devops@example.com')
 *             ->sendOutputTo(storage_path('logs/queue-output.log'));
 *     }
 * }
 *
 * // Register in bootstrap/app.php:
 * ->withSchedule(function (Schedule $schedule) {
 *     ProcessQueue::schedule($schedule);
 * })
 * @example
 * // Using asSchedule() method for custom execution:
 * class CustomScheduledTask extends Actions
 * {
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Default execution
 *     }
 *
 *     // Custom method called when scheduled
 *     public function asSchedule(): void
 *     {
 *         // Custom scheduled execution logic
 *         $this->handle();
 *         $this->sendNotification();
 *     }
 *
 *     protected function sendNotification(): void
 *     {
 *         // Send notification after execution
 *     }
 *
 *     public function getScheduleFrequency(): string
 *     {
 *         return 'daily';
 *     }
 * }
 *
 * // The ScheduleDecorator will call asSchedule() if it exists,
 * // otherwise falls back to handle()
 */
trait AsSchedule
{
    public static function schedule(Schedule $schedule): void
    {
        $instance = static::make();
        $scheduled = $schedule->call(function () {
            static::run();
        });

        if ($instance->hasMethod('getScheduleExpression')) {
            $expression = $instance->callMethod('getScheduleExpression');
            $scheduled->cron($expression);
        } elseif ($instance->hasMethod('getScheduleFrequency')) {
            $frequency = $instance->callMethod('getScheduleFrequency');
            if (method_exists($scheduled, $frequency)) {
                $scheduled->{$frequency}();
            } else {
                $scheduled->daily();
            }
        } else {
            $scheduled->daily();
        }

        if ($instance->hasMethod('configureSchedule')) {
            $instance->callMethod('configureSchedule', [$scheduled]);
        }
    }
}
