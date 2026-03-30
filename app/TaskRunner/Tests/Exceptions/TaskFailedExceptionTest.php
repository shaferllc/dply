<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Exceptions\TaskFailedException;

test('can create TaskFailedException with default constructor', function () {
    $exception = new TaskFailedException;

    expect($exception)->toBeInstanceOf(TaskFailedException::class)
        ->and($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('')
        ->and($exception->getCode())->toBe(0);
});

test('can create TaskFailedException with custom message', function () {
    $message = 'Task execution failed due to timeout';
    $exception = new TaskFailedException($message);

    expect($exception->getMessage())->toBe($message);
});

test('can create TaskFailedException with custom message and code', function () {
    $message = 'Task execution failed due to timeout';
    $code = 500;
    $exception = new TaskFailedException($message, $code);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getCode())->toBe($code);
});

test('can create TaskFailedException with previous exception', function () {
    $previousException = new Exception('Previous error');
    $exception = new TaskFailedException('Task failed', 0, $previousException);

    expect($exception->getPrevious())->toBe($previousException);
});

test('TaskFailedException has correct namespace', function () {
    $exception = new TaskFailedException;

    expect($exception)->toBeInstanceOf(TaskFailedException::class);
});
