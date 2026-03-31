<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Services;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\TaskRunnerService;
use Carbon\Carbon;

/**
 * Task Scheduling Service
 *
 * Provides task scheduling, calendar visualization, and recurring task management.
 * Enables teams to plan, schedule, and visualize task execution timelines.
 *
 * Used by:
 * - Task scheduling UI (Livewire components)
 * - Calendar views
 * - Recurring task setup
 * - Task planning dashboards
 *
 * Integration points:
 * - TaskRunner core: Task execution scheduling
 * - Teams module: Team-scoped scheduling
 * - Laravel Scheduler: Cron-based task execution
 * - Calendar libraries: Visual scheduling display
 *
 * @see TaskRunnerService
 * @see Task
 */
class TaskSchedulingService
{
    /**
     * Get calendar view for a specific month
     *
     * @param  string  $month  Month in Y-m format (e.g., '2025-10')
     * @return array Calendar data with tasks
     */
    public function getCalendarView(string $month): array
    {
        $date = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $daysInMonth = $date->daysInMonth;

        $days = [];
        $tasks = [];

        // Build days array
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDate = $date->copy()->day($day);
            $days[] = [
                'date' => $currentDate->format('Y-m-d'),
                'day' => $day,
                'day_name' => $currentDate->format('D'),
                'is_today' => $currentDate->isToday(),
                'is_weekend' => $currentDate->isWeekend(),
            ];
        }

        // Get tasks for the month
        $startDate = $date->copy()->startOfMonth();
        $endDate = $date->copy()->endOfMonth();

        $monthTasks = Task::whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at')
            ->get();

        // Group tasks by day
        $tasks = $monthTasks->groupBy(fn ($task) => $task->created_at->format('Y-m-d'))
            ->map(fn ($dayTasks) => $dayTasks->map(fn ($task) => [
                'id' => $task->id,
                'name' => $task->name,
                'status' => $task->status->value,
                'time' => $task->created_at->format('H:i'),
            ])->toArray())
            ->toArray();

        return [
            'month' => $date->format('F Y'),
            'days' => $days,
            'tasks' => $tasks,
            'stats' => [
                'total' => $monthTasks->count(),
                'completed' => $monthTasks->where('status', TaskStatus::Finished)->count(),
                'failed' => $monthTasks->where('status', TaskStatus::Failed)->count(),
            ],
        ];
    }

    /**
     * Schedule recurring task
     *
     * @param  string  $name  Task name
     * @param  string  $script  Script to execute
     * @param  string  $schedule  Cron expression
     * @param  array  $options  Task options
     * @return array Scheduling result
     */
    public function scheduleRecurring(string $name, string $script, string $schedule, array $options = []): array
    {
        // Validate cron expression
        if (! $this->isValidCronExpression($schedule)) {
            return [
                'success' => false,
                'error' => 'Invalid cron expression',
            ];
        }

        // Create recurring task record
        $taskData = [
            'name' => $name,
            'script' => $script,
            'schedule' => $schedule,
            'options' => $options,
            'is_recurring' => true,
        ];

        return [
            'success' => true,
            'task_data' => $taskData,
            'next_run' => $this->getNextRunTime($schedule),
        ];
    }

    /**
     * Validate cron expression
     *
     * @param  string  $expression  Cron expression to validate
     * @return bool Valid or not
     */
    protected function isValidCronExpression(string $expression): bool
    {
        // Basic validation - 5 or 6 parts separated by spaces
        $parts = explode(' ', trim($expression));

        return in_array(count($parts), [5, 6]);
    }

    /**
     * Get next run time for cron expression
     *
     * @param  string  $cronExpression  Cron expression
     * @return string Next run time
     */
    protected function getNextRunTime(string $cronExpression): string
    {
        // Simplified - would use actual cron library in production
        return now()->addHour()->format('Y-m-d H:i:s');
    }

    /**
     * Get upcoming scheduled tasks
     *
     * @param  int  $days  Days ahead to look
     * @return array Upcoming tasks
     */
    public function getUpcomingTasks(int $days = 7): array
    {
        $endDate = now()->addDays($days);

        $tasks = Task::where('status', TaskStatus::Pending)
            ->where('created_at', '<=', $endDate)
            ->orderBy('created_at')
            ->get();

        return $tasks->map(fn ($task) => [
            'id' => $task->id,
            'name' => $task->name,
            'scheduled_at' => $task->created_at->format('Y-m-d H:i'),
            'days_until' => now()->diffInDays($task->created_at, false),
        ])->toArray();
    }

    /**
     * Get task schedule conflicts
     *
     * @param  string  $month  Month to check
     * @return array Scheduling conflicts
     */
    public function getScheduleConflicts(string $month): array
    {
        $date = Carbon::createFromFormat('Y-m', $month);
        $tasks = Task::whereBetween('created_at', [
            $date->copy()->startOfMonth(),
            $date->copy()->endOfMonth(),
        ])->get();

        // Group by hour to find conflicts
        $conflicts = $tasks->groupBy(fn ($task) => $task->created_at->format('Y-m-d H'))
            ->filter(fn ($group) => $group->count() > 3)
            ->map(fn ($group) => [
                'time' => $group->first()->created_at->format('Y-m-d H:00'),
                'count' => $group->count(),
                'tasks' => $group->pluck('name')->toArray(),
            ])
            ->values()
            ->toArray();

        return [
            'has_conflicts' => ! empty($conflicts),
            'conflicts' => $conflicts,
            'recommendation' => ! empty($conflicts)
                ? 'Distribute tasks more evenly throughout the day'
                : 'No scheduling conflicts detected',
        ];
    }
}
