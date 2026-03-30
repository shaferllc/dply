<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Events;

use App\Modules\TaskRunner\Events\TaskChainProgress;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);

describe('TaskChainProgress Event', function () {
    beforeEach(function () {
        Event::fake();
    });

    it('creates event with required properties', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $chainId = 'chain-123';
        $currentTask = 2;
        $totalTasks = 3;
        $message = 'Processing task chain';
        $startedAt = now()->toISOString();

        $event = new TaskChainProgress($tasks, $chainId, $currentTask, $totalTasks, $message, $startedAt);

        expect($event->tasks)->toBe($tasks);
        expect($event->chainId)->toBe($chainId);
        expect($event->currentTask)->toBe($currentTask);
        expect($event->totalTasks)->toBe($totalTasks);
        expect($event->message)->toBe($message);
        expect($event->startedAt)->toBe($startedAt);
    });

    it('calculates percentage correctly', function () {
        $tasks = [new TestTask, new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 4, 'Processing', now()->toISOString());

        expect($event->getPercentage())->toBe(50.0);
    });

    it('calculates percentage with zero total tasks', function () {
        $tasks = [];
        $event = new TaskChainProgress($tasks, 'chain-123', 0, 0, 'No tasks', now()->toISOString());

        expect($event->getPercentage())->toBe(0.0);
    });

    it('calculates percentage integer correctly', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, 'Processing', now()->toISOString());

        expect($event->getPercentageInt())->toBe(67);
    });

    it('calculates percentage integer with rounding', function () {
        $tasks = [new TestTask, new TestTask, new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 5, 'Processing', now()->toISOString());

        expect($event->getPercentageInt())->toBe(40);
    });

    it('calculates progress ratio correctly', function () {
        $tasks = [new TestTask, new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 4, 'Processing', now()->toISOString());

        expect($event->getProgressRatio())->toBe(0.5);
    });

    it('calculates progress ratio with zero total tasks', function () {
        $tasks = [];
        $event = new TaskChainProgress($tasks, 'chain-123', 0, 0, 'No tasks', now()->toISOString());

        expect($event->getProgressRatio())->toBe(0.0);
    });

    it('calculates remaining tasks correctly', function () {
        $tasks = [new TestTask, new TestTask, new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 5, 'Processing', now()->toISOString());

        expect($event->getRemainingTasks())->toBe(3);
    });

    it('calculates remaining tasks when complete', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 3, 3, 'Complete', now()->toISOString());

        expect($event->getRemainingTasks())->toBe(0);
    });

    it('calculates remaining tasks when over complete', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 5, 3, 'Over complete', now()->toISOString());

        expect($event->getRemainingTasks())->toBe(0);
    });

    it('checks if chain is complete when current equals total', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 3, 3, 'Complete', now()->toISOString());

        expect($event->isComplete())->toBeTrue();
    });

    it('checks if chain is complete when current exceeds total', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 5, 3, 'Over complete', now()->toISOString());

        expect($event->isComplete())->toBeTrue();
    });

    it('checks if chain is not complete when current is less than total', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, 'In progress', now()->toISOString());

        expect($event->isComplete())->toBeFalse();
    });

    it('checks if chain is in progress', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, 'In progress', now()->toISOString());

        expect($event->isInProgress())->toBeTrue();
    });

    it('checks if chain is not in progress when complete', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 3, 3, 'Complete', now()->toISOString());

        expect($event->isInProgress())->toBeFalse();
    });

    it('checks if chain is starting', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 1, 3, 'Starting', now()->toISOString());

        expect($event->isStarting())->toBeTrue();
    });

    it('checks if chain is not starting when not first task', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, 'In progress', now()->toISOString());

        expect($event->isStarting())->toBeFalse();
    });

    it('generates progress bar correctly', function () {
        $tasks = [new TestTask, new TestTask, new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 3, 5, 'Processing', now()->toISOString());

        $progressBar = $event->getProgressBar(10);
        expect($progressBar)->toHaveLength(10);
        expect($progressBar)->toContain('█');
        expect($progressBar)->toContain('░');
    });

    it('generates progress bar with custom width', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, 'Processing', now()->toISOString());

        $progressBar = $event->getProgressBar(20);
        expect($progressBar)->toHaveLength(20);
    });

    it('generates progress bar when complete', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 3, 3, 'Complete', now()->toISOString());

        $progressBar = $event->getProgressBar(10);
        expect($progressBar)->toBe(str_repeat('█', 10));
    });

    it('gets current task name', function () {
        $task1 = new TestTask('Task 1');
        $task2 = new TestTask('Task 2');
        $task3 = new TestTask('Task 3');

        $tasks = [$task1, $task2, $task3];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, 'Processing', now()->toISOString());

        expect($event->getCurrentTaskName())->toBe('Task 2');
    });

    it('gets current task name with index out of bounds', function () {
        $tasks = [new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 5, 2, 'Processing', now()->toISOString());

        expect($event->getCurrentTaskName())->toBe('Task 5');
    });

    it('gets current task name with empty tasks array', function () {
        $tasks = [];
        $event = new TaskChainProgress($tasks, 'chain-123', 1, 0, 'Processing', now()->toISOString());

        expect($event->getCurrentTaskName())->toBe('Task 1');
    });

    it('gets progress details', function () {
        $tasks = [new TestTask, new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 4, 'Processing', now()->toISOString());

        $details = $event->getProgressDetails();

        expect($details)->toHaveKeys([
            'current_task',
            'total_tasks',
            'percentage',
            'percentage_int',
            'progress_ratio',
            'remaining_tasks',
            'is_complete',
            'is_in_progress',
            'is_starting',
            'current_task_name',
            'progress_bar',
        ]);

        expect($details['current_task'])->toBe(2);
        expect($details['total_tasks'])->toBe(4);
        expect($details['percentage'])->toBe(50.0);
        expect($details['percentage_int'])->toBe(50);
        expect($details['progress_ratio'])->toBe(0.5);
        expect($details['remaining_tasks'])->toBe(2);
        expect($details['is_complete'])->toBeFalse();
        expect($details['is_in_progress'])->toBeTrue();
        expect($details['is_starting'])->toBeFalse();
    });

    it('can be serialized and unserialized', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $chainId = 'chain-123';
        $currentTask = 2;
        $totalTasks = 3;
        $message = 'Processing task chain';
        $startedAt = now()->toISOString();

        $event = new TaskChainProgress($tasks, $chainId, $currentTask, $totalTasks, $message, $startedAt);

        $serialized = serialize($event);
        $unserialized = unserialize($serialized);

        expect($unserialized)->toBeInstanceOf(TaskChainProgress::class);
        expect($unserialized->tasks)->toHaveCount(count($tasks));
        expect($unserialized->chainId)->toBe($chainId);
        expect($unserialized->currentTask)->toBe($currentTask);
        expect($unserialized->totalTasks)->toBe($totalTasks);
        expect($unserialized->message)->toBe($message);
        expect($unserialized->startedAt)->toBe($startedAt);
    });

    it('can be dispatched', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, 'Processing', now()->toISOString());

        Event::dispatch($event);

        Event::assertDispatched(TaskChainProgress::class, function ($dispatchedEvent) use ($event) {
            return $dispatchedEvent->chainId === $event->chainId &&
                   $dispatchedEvent->currentTask === $event->currentTask &&
                   $dispatchedEvent->totalTasks === $event->totalTasks &&
                   $dispatchedEvent->message === $event->message &&
                   $dispatchedEvent->startedAt === $event->startedAt;
        });
    });

    it('handles edge case with zero current and total tasks', function () {
        $tasks = [];
        $event = new TaskChainProgress($tasks, 'chain-123', 0, 0, 'No tasks', now()->toISOString());

        expect($event->getPercentage())->toBe(0.0);
        expect($event->getPercentageInt())->toBe(0);
        expect($event->getProgressRatio())->toBe(0.0);
        expect($event->getRemainingTasks())->toBe(0);
        expect($event->isComplete())->toBeTrue();
        expect($event->isInProgress())->toBeFalse();
        expect($event->isStarting())->toBeFalse();
    });

    it('handles edge case with negative current task', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', -1, 3, 'Negative progress', now()->toISOString());

        expect($event->getRemainingTasks())->toBe(4); // 3 - (-1) = 4
        expect($event->isComplete())->toBeFalse();
        expect($event->getProgressRatio())->toBe(-1 / 3);
    });

    it('handles edge case with very large numbers', function () {
        $tasks = array_fill(0, 1000, new TestTask);
        $currentTask = PHP_INT_MAX - 1000;
        $totalTasks = PHP_INT_MAX;

        $event = new TaskChainProgress($tasks, 'chain-123', $currentTask, $totalTasks, 'Large numbers', now()->toISOString());

        expect($event->getRemainingTasks())->toBe(1000);
        expect($event->isComplete())->toBeFalse();
        expect($event->getProgressRatio())->toBe(($currentTask / $totalTasks));
    });

    it('handles floating point precision in percentage calculations', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 1, 3, 'Precision test', now()->toISOString());

        expect($event->getProgressRatio())->toBe(1 / 3);
        expect($event->getPercentageInt())->toBe(33);
    });

    it('validates timestamp format', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $startedAt = now()->toISOString();
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, 'Processing', $startedAt);

        expect($event->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
    });

    it('handles empty message', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, '', now()->toISOString());

        expect($event->message)->toBe('');
    });

    it('handles very long message', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $longMessage = str_repeat('A very long message that exceeds normal length ', 10);
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, $longMessage, now()->toISOString());

        expect($event->message)->toBe($longMessage);
    });

    it('handles special characters in message', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $specialMessage = 'Processing with special chars: !@#$%^&*()_+-=[]{}|;:,.<>?';
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, $specialMessage, now()->toISOString());

        expect($event->message)->toBe($specialMessage);
    });

    it('handles unicode characters in message', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $unicodeMessage = 'Processing with unicode: 你好世界 🌍 🚀';
        $event = new TaskChainProgress($tasks, 'chain-123', 2, 3, $unicodeMessage, now()->toISOString());

        expect($event->message)->toBe($unicodeMessage);
    });
});
