<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests;

use App\Modules\TaskRunner\Examples\ComplexTask;
use App\Modules\TaskRunner\Examples\SimpleTask;
use App\Modules\TaskRunner\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(LazilyRefreshDatabase::class, TestCase::class);

test('simple task works with Task::fake()', function () {
    // Enable fake mode
    Task::fake();

    $task = new SimpleTask;

    // Test initial state
    expect($task->getName())->toBe('simple-task');
    expect($task->getAction())->toBe('simple');
    expect($task->getScript())->toBe('echo "This is a simple task"');

    // Execute the task
    $task->handle();

    // Test final state
    expect($task->getStatus()->value)->toBe('finished');
    expect($task->getExitCode())->toBe(0);
    expect($task->getOutput())->toBe('Hello World'); // Fake mode returns this

    // Disable fake mode
    Task::unfake();
});

test('complex task works with Task::fake()', function () {
    // Enable fake mode
    Task::fake();

    $task = new ComplexTask;

    // Test initial state
    expect($task->getName())->toBe('complex-task');
    expect($task->getAction())->toBe('complex');
    expect($task->getOption('message'))->toBe('Hello from complex task');
    expect($task->getOption('count'))->toBe(3);

    // The script should include the options
    $script = $task->getScript();
    expect($script)->toContain('Hello from complex task');
    expect($script)->toContain('iteration 1');
    expect($script)->toContain('iteration 2');
    expect($script)->toContain('iteration 3');

    // Execute the task
    $task->handle();

    // Test final state
    expect($task->getStatus()->value)->toBe('finished');
    expect($task->getExitCode())->toBe(0);

    // Disable fake mode
    Task::unfake();
});

test('complex task with custom options', function () {
    // Enable fake mode
    Task::fake();

    $task = new ComplexTask;
    $task->setOption('message', 'Custom message');
    $task->setOption('count', 2);

    // Test custom options
    expect($task->getOption('message'))->toBe('Custom message');
    expect($task->getOption('count'))->toBe(2);

    // The script should reflect custom options
    $script = $task->getScript();
    expect($script)->toContain('Custom message');
    expect($script)->toContain('iteration 1');
    expect($script)->toContain('iteration 2');
    expect($script)->not->toContain('iteration 3');

    // Execute the task
    $task->handle();

    // Test final state
    expect($task->getStatus()->value)->toBe('finished');

    // Disable fake mode
    Task::unfake();
});
