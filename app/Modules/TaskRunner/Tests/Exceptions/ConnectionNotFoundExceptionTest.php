<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Exceptions\ConnectionNotFoundException;

test('can create ConnectionNotFoundException with default constructor', function () {
    $exception = new ConnectionNotFoundException;

    expect($exception)->toBeInstanceOf(ConnectionNotFoundException::class)
        ->and($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('')
        ->and($exception->getCode())->toBe(0);
});

test('can create ConnectionNotFoundException with custom message', function () {
    $message = 'Connection not found: server-123';
    $exception = new ConnectionNotFoundException($message);

    expect($exception->getMessage())->toBe($message);
});

test('can create ConnectionNotFoundException with custom message and code', function () {
    $message = 'Connection not found: server-123';
    $code = 8001;
    $exception = new ConnectionNotFoundException($message, $code);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getCode())->toBe($code);
});

test('can create ConnectionNotFoundException with previous exception', function () {
    $previousException = new Exception('Previous connection error');
    $exception = new ConnectionNotFoundException('Connection not found', 0, $previousException);

    expect($exception->getPrevious())->toBe($previousException);
});

test('ConnectionNotFoundException has correct namespace', function () {
    $exception = new ConnectionNotFoundException;

    expect($exception)->toBeInstanceOf(ConnectionNotFoundException::class);
});

test('ConnectionNotFoundException can be thrown and caught', function () {
    expect(function () {
        throw new ConnectionNotFoundException('Test connection not found');
    })->toThrow(ConnectionNotFoundException::class, 'Test connection not found');
});

test('can create exception with empty message', function () {
    $exception = new ConnectionNotFoundException('');

    expect($exception->getMessage())->toBe('');
});

test('can create exception with zero code', function () {
    $exception = new ConnectionNotFoundException('Connection error', 0);

    expect($exception->getCode())->toBe(0);
});
