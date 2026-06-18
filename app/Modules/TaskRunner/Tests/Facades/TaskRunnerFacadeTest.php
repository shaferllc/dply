<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Facades;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\TaskChain;
use Tests\TestCase;

uses(TestCase::class);

describe('TaskRunner Facade', function () {
    describe('Task Chain Creation', function () {
        it('can create a new task chain', function () {
            $chain = TaskRunner::chain();

            expect($chain)->toBeInstanceOf(TaskChain::class);
        });
    });

    describe('Anonymous Task Creation', function () {
        it('can create anonymous tasks with command', function () {
            $task = TaskRunner::command('Test Command', 'ls -la');

            expect($task)->toBeInstanceOf(AnonymousTask::class);
            expect($task->getName())->toBe('Test Command');
            expect($task->getAction())->toBe('script');
        });

        it('can create anonymous tasks with commands array', function () {
            $task = TaskRunner::commands('Test Commands', [
                'echo "First command"',
                'echo "Second command"',
            ]);

            expect($task)->toBeInstanceOf(AnonymousTask::class);
            expect($task->getName())->toBe('Test Commands');
            expect($task->getAction())->toBe('script');
        });

        it('can create anonymous tasks with view', function () {
            $task = TaskRunner::view('Test View', 'test-view', ['key' => 'value']);

            expect($task)->toBeInstanceOf(AnonymousTask::class);
            expect($task->getName())->toBe('Test View');
            expect($task->getAction())->toBe('view');
            expect($task->getViewData())->toHaveKey('key');
        });

        it('can create anonymous tasks with callback', function () {
            $callback = function ($task) {
                return 'echo "Callback executed"';
            };

            $task = TaskRunner::callback('Test Callback', $callback);

            expect($task)->toBeInstanceOf(AnonymousTask::class);
            expect($task->getName())->toBe('Test Callback');
            expect($task->getAction())->toBe('callback');
        });

        it('can create anonymous tasks with anonymous method', function () {
            $task = TaskRunner::anonymous('Test Anonymous', 'echo "Anonymous task"');

            expect($task)->toBeInstanceOf(AnonymousTask::class);
            expect($task->getName())->toBe('Test Anonymous');
            expect($task->getAction())->toBe('script');
        });
    });

    describe('Testing Methods', function () {
        it('can prevent stray tasks', function () {
            TaskRunner::preventStrayTasks();

            // This should not throw an exception since we're just setting the flag
            expect(true)->toBeTrue();
        });

        it('can assert not dispatched when no events are dispatched', function () {
            TaskRunner::assertNotDispatched('NonExistentEvent');
        });
    });

    describe('AnonymousTask Properties', function () {
        it('can set and retrieve task properties', function () {
            $task = TaskRunner::command('Property Test', 'echo "test"');

            expect($task->getName())->toBe('Property Test');
            expect($task->getAction())->toBe('script');
            expect($task->getTimeout())->toBeNull(); // Default timeout
        });

        it('can create task with custom timeout', function () {
            $task = TaskRunner::command('Timeout Test', 'echo "test"', ['timeout' => 120]);

            expect($task->getTimeout())->toBe(120);
        });

        it('can create task with custom data', function () {
            $task = TaskRunner::view('Data Test', 'test-view', ['custom' => 'value'], ['timeout' => 60]);

            expect($task->getViewData())->toHaveKey('custom');
            expect($task->getViewData()['custom'])->toBe('value');
            expect($task->getTimeout())->toBe(60);
        });
    });

    describe('Task Chain Building', function () {
        it('can build a task chain with multiple tasks', function () {
            $chain = TaskRunner::chain()
                ->add(TaskRunner::command('Task 1', 'echo "1"'))
                ->add(TaskRunner::command('Task 2', 'echo "2"'))
                ->add(TaskRunner::command('Task 3', 'echo "3"'));

            expect($chain)->toBeInstanceOf(TaskChain::class);
            expect($chain->getTasks())->toHaveCount(3);
        });

        it('can build a task chain with different task types', function () {
            $chain = TaskRunner::chain()
                ->add(TaskRunner::command('Command Task', 'echo "command"'))
                ->add(TaskRunner::view('View Task', 'test-view', ['data' => 'value']))
                ->add(TaskRunner::callback('Callback Task', fn () => 'echo "callback"'));

            expect($chain)->toBeInstanceOf(TaskChain::class);
            expect($chain->getTasks())->toHaveCount(3);
        });
    });
});
