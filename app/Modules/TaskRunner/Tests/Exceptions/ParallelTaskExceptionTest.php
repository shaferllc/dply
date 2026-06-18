<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Exceptions\ParallelTaskException;

test('can create ParallelTaskException with default constructor', function () {
    $exception = new ParallelTaskException;

    expect($exception)->toBeInstanceOf(ParallelTaskException::class)
        ->and($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('')
        ->and($exception->getCode())->toBe(0);
});

test('can create ParallelTaskException with custom message', function () {
    $message = 'Parallel task execution failed';
    $exception = new ParallelTaskException($message);

    expect($exception->getMessage())->toBe($message);
});

test('can create ParallelTaskException with custom message and code', function () {
    $message = 'Parallel task execution failed';
    $code = 1001;
    $exception = new ParallelTaskException($message, $code);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getCode())->toBe($code);
});

test('can create ParallelTaskException with previous exception', function () {
    $previousException = new Exception('Previous parallel error');
    $exception = new ParallelTaskException('Parallel task failed', 0, $previousException);

    expect($exception->getPrevious())->toBe($previousException);
});

test('ParallelTaskException has correct namespace', function () {
    $exception = new ParallelTaskException;

    expect($exception)->toBeInstanceOf(ParallelTaskException::class);
});

test('ParallelTaskException can be thrown and caught', function () {
    expect(function () {
        throw new ParallelTaskException('Test parallel exception');
    })->toThrow(ParallelTaskException::class, 'Test parallel exception');
});
