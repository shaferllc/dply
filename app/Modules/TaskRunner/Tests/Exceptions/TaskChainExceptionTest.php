<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Exceptions\TaskChainException;

test('can create TaskChainException with default constructor', function () {
    $exception = new TaskChainException;

    expect($exception)->toBeInstanceOf(TaskChainException::class)
        ->and($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('')
        ->and($exception->getCode())->toBe(0)
        ->and($exception->getFailedTaskNumber())->toBeNull()
        ->and($exception->getChainResults())->toBe([]);
});

test('can create TaskChainException with all parameters', function () {
    $message = 'Task chain failed';
    $code = 3001;
    $failedTaskNumber = 3;
    $chainResults = ['total_tasks' => 5, 'successful_tasks' => 2, 'failed_tasks' => 1];

    $exception = new TaskChainException($message, $code, null, $failedTaskNumber, $chainResults);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getCode())->toBe($code)
        ->and($exception->getFailedTaskNumber())->toBe($failedTaskNumber)
        ->and($exception->getChainResults())->toBe($chainResults);
});

test('can create TaskChainException with previous exception', function () {
    $previousException = new Exception('Previous chain error');
    $exception = new TaskChainException('Chain failed', 0, $previousException);

    expect($exception->getPrevious())->toBe($previousException);
});

test('setChainResults updates chain results and returns self', function () {
    $exception = new TaskChainException;
    $newResults = ['total_tasks' => 3, 'successful_tasks' => 1];

    $result = $exception->setChainResults($newResults);

    expect($result)->toBe($exception)
        ->and($exception->getChainResults())->toBe($newResults);
});

test('hasSuccessfulTasks returns true when successful tasks exist', function () {
    $chainResults = ['successful_tasks' => 2];
    $exception = new TaskChainException('', 0, null, null, $chainResults);

    expect($exception->hasSuccessfulTasks())->toBeTrue();
});

test('hasSuccessfulTasks returns false when no successful tasks', function () {
    $chainResults = ['successful_tasks' => 0];
    $exception = new TaskChainException('', 0, null, null, $chainResults);

    expect($exception->hasSuccessfulTasks())->toBeFalse();
});

test('hasSuccessfulTasks returns false when successful_tasks key does not exist', function () {
    $exception = new TaskChainException;

    expect($exception->hasSuccessfulTasks())->toBeFalse();
});

test('getSuccessfulTaskCount returns correct count', function () {
    $chainResults = ['successful_tasks' => 3];
    $exception = new TaskChainException('', 0, null, null, $chainResults);

    expect($exception->getSuccessfulTaskCount())->toBe(3);
});

test('getSuccessfulTaskCount returns zero when key does not exist', function () {
    $exception = new TaskChainException;

    expect($exception->getSuccessfulTaskCount())->toBe(0);
});

test('getFailedTaskCount returns correct count', function () {
    $chainResults = ['failed_tasks' => 2];
    $exception = new TaskChainException('', 0, null, null, $chainResults);

    expect($exception->getFailedTaskCount())->toBe(2);
});

test('getFailedTaskCount returns zero when key does not exist', function () {
    $exception = new TaskChainException;

    expect($exception->getFailedTaskCount())->toBe(0);
});

test('getTotalTaskCount returns correct count', function () {
    $chainResults = ['total_tasks' => 5];
    $exception = new TaskChainException('', 0, null, null, $chainResults);

    expect($exception->getTotalTaskCount())->toBe(5);
});

test('getTotalTaskCount returns zero when key does not exist', function () {
    $exception = new TaskChainException;

    expect($exception->getTotalTaskCount())->toBe(0);
});

test('TaskChainException has correct namespace', function () {
    $exception = new TaskChainException;

    expect($exception)->toBeInstanceOf(TaskChainException::class);
});

test('can access all properties and methods correctly', function () {
    $message = 'Test task chain exception';
    $failedTaskNumber = 2;
    $chainResults = [
        'total_tasks' => 4,
        'successful_tasks' => 1,
        'failed_tasks' => 1,
    ];

    $exception = new TaskChainException($message, 0, null, $failedTaskNumber, $chainResults);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getFailedTaskNumber())->toBe($failedTaskNumber)
        ->and($exception->getChainResults())->toBe($chainResults)
        ->and($exception->hasSuccessfulTasks())->toBeTrue()
        ->and($exception->getSuccessfulTaskCount())->toBe(1)
        ->and($exception->getFailedTaskCount())->toBe(1)
        ->and($exception->getTotalTaskCount())->toBe(4);
});

test('can modify chain results after creation', function () {
    $exception = new TaskChainException;
    $initialResults = ['total_tasks' => 2];
    $updatedResults = ['total_tasks' => 3, 'successful_tasks' => 1];

    $exception->setChainResults($initialResults);
    expect($exception->getChainResults())->toBe($initialResults);

    $exception->setChainResults($updatedResults);
    expect($exception->getChainResults())->toBe($updatedResults)
        ->and($exception->getTotalTaskCount())->toBe(3)
        ->and($exception->getSuccessfulTaskCount())->toBe(1);
});
