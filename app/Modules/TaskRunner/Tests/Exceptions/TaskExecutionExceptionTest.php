<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Exceptions\TaskExecutionException;

test('can create TaskExecutionException with default constructor', function () {
    $exception = new TaskExecutionException;

    expect($exception)->toBeInstanceOf(TaskExecutionException::class)
        ->and($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('Task execution failed.')
        ->and($exception->getCode())->toBe(0)
        ->and($exception->getOutput())->toBeNull();
});

test('can create TaskExecutionException with custom message', function () {
    $message = 'Custom execution error message';
    $exception = new TaskExecutionException($message);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getOutput())->toBeNull();
});

test('can create TaskExecutionException with custom message and code', function () {
    $message = 'Custom execution error message';
    $code = 6001;
    $exception = new TaskExecutionException($message, $code);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getCode())->toBe($code)
        ->and($exception->getOutput())->toBeNull();
});

test('can create TaskExecutionException with output', function () {
    $message = 'Task execution failed';
    $output = 'Command output: permission denied';
    $exception = new TaskExecutionException($message, 0, $output);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getOutput())->toBe($output);
});

test('can create TaskExecutionException with all parameters', function () {
    $message = 'Complete execution error';
    $code = 6002;
    $output = 'Detailed error output';
    $previousException = new Exception('Previous execution error');

    $exception = new TaskExecutionException($message, $code, $output, $previousException);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getCode())->toBe($code)
        ->and($exception->getOutput())->toBe($output)
        ->and($exception->getPrevious())->toBe($previousException);
});

test('getOutput returns null by default', function () {
    $exception = new TaskExecutionException;

    expect($exception->getOutput())->toBeNull();
});

test('getOutput returns provided output', function () {
    $output = 'Task failed with exit code 1';
    $exception = new TaskExecutionException('Task failed', 0, $output);

    expect($exception->getOutput())->toBe($output);
});

test('TaskExecutionException has correct namespace', function () {
    $exception = new TaskExecutionException;

    expect($exception)->toBeInstanceOf(TaskExecutionException::class);
});

test('can create exception with empty output string', function () {
    $exception = new TaskExecutionException('Task failed', 0, '');

    expect($exception->getOutput())->toBe('');
});

test('can access all properties correctly', function () {
    $message = 'Test execution exception';
    $code = 7001;
    $output = 'Test output content';

    $exception = new TaskExecutionException($message, $code, $output);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getCode())->toBe($code)
        ->and($exception->getOutput())->toBe($output);
});

test('can create exception with Throwable previous exception', function () {
    $previousException = new Error('Previous error');
    $exception = new TaskExecutionException('Task failed', 0, null, $previousException);

    expect($exception->getPrevious())->toBe($previousException);
});
