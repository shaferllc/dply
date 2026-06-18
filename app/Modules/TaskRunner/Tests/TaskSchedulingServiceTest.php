<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\Services\TaskSchedulingService;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->service = new TaskSchedulingService;
});

it('get calendar view returns calendar data', function () {
    $calendar = $this->service->getCalendarView('2025-10');

    expect($calendar)->toHaveKeys(['month', 'days', 'tasks', 'stats'])
        ->and($calendar['month'])->toBe('October 2025')
        ->and($calendar['days'])->toBeArray();
});

it('calendar view includes all days of month', function () {
    $calendar = $this->service->getCalendarView('2025-10');

    expect(count($calendar['days']))->toBe(31); // October has 31 days
});

it('calendar view groups tasks by day', function () {
    $date = Carbon::create(2025, 10, 15, 10, 0, 0);
    Task::factory()->count(2)->create(['created_at' => $date]);

    $calendar = $this->service->getCalendarView('2025-10');

    $dayKey = $date->format('Y-m-d');
    expect($calendar['tasks'])->toHaveKey($dayKey);
});

it('schedule recurring task validates cron expression', function () {
    $result = $this->service->scheduleRecurring('Daily Task', 'echo "test"', '0 0 * * *');

    expect($result['success'])->toBeTrue()
        ->and($result)->toHaveKey('next_run');
});

it('schedule recurring task rejects invalid cron', function () {
    $result = $this->service->scheduleRecurring('Bad Task', 'echo "test"', 'invalid cron');

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Invalid cron expression');
});

it('get upcoming tasks returns pending tasks', function () {
    Task::factory()->create([
        'status' => TaskStatus::Pending,
        'created_at' => now()->addDays(2),
    ]);

    $upcoming = $this->service->getUpcomingTasks(7);

    expect($upcoming)->toBeArray();
});

it('get schedule conflicts detects busy periods', function () {
    $date = Carbon::create(2025, 10, 15, 10, 0, 0);

    // Create 5 tasks in the same hour to trigger conflict detection
    for ($i = 0; $i < 5; $i++) {
        Task::factory()->create(['created_at' => $date->copy()->addMinutes($i * 10)]);
    }

    $conflicts = $this->service->getScheduleConflicts('2025-10');

    expect($conflicts)->toHaveKeys(['has_conflicts', 'conflicts', 'recommendation']);
});

it('get schedule conflicts returns empty when no conflicts', function () {
    Task::factory()->create(['created_at' => Carbon::create(2025, 10, 15, 10, 0, 0)]);
    Task::factory()->create(['created_at' => Carbon::create(2025, 10, 15, 14, 0, 0)]);

    $conflicts = $this->service->getScheduleConflicts('2025-10');

    expect($conflicts['has_conflicts'])->toBeFalse()
        ->and($conflicts['conflicts'])->toBeEmpty();
});
