<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Exceptions\RollbackException;

test('can create RollbackException with default constructor', function () {
    $exception = new RollbackException;

    expect($exception)->toBeInstanceOf(RollbackException::class)
        ->and($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('')
        ->and($exception->getCode())->toBe(0)
        ->and($exception->getTaskId())->toBeNull()
        ->and($exception->getRollbackReason())->toBeNull()
        ->and($exception->getContext())->toBe([]);
});

test('can create RollbackException with all parameters', function () {
    $message = 'Rollback failed';
    $code = 9001;
    $taskId = 'task-123';
    $rollbackReason = 'validation_failed';
    $context = ['field' => 'value'];
    $previousException = new Exception('Previous rollback error');

    $exception = new RollbackException($message, $code, $previousException, $taskId, $rollbackReason, $context);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getCode())->toBe($code)
        ->and($exception->getTaskId())->toBe($taskId)
        ->and($exception->getRollbackReason())->toBe($rollbackReason)
        ->and($exception->getContext())->toBe($context)
        ->and($exception->getPrevious())->toBe($previousException);
});

test('validationFailed static method creates exception with validation errors', function () {
    $taskId = 'task-456';
    $validationErrors = ['field1' => 'Required', 'field2' => 'Invalid format'];

    $exception = RollbackException::validationFailed($taskId, $validationErrors);

    expect($exception)->toBeInstanceOf(RollbackException::class)
        ->and($exception->getMessage())->toContain('Rollback validation failed')
        ->and($exception->getTaskId())->toBe($taskId)
        ->and($exception->getRollbackReason())->toBe('validation_failed')
        ->and($exception->getContext())->toHaveKey('validation_errors')
        ->and($exception->getContext()['validation_errors'])->toBe($validationErrors);
});

test('executionFailed static method creates exception with execution error', function () {
    $taskId = 'task-789';
    $reason = 'timeout';
    $error = 'Process timed out after 30 seconds';

    $exception = RollbackException::executionFailed($taskId, $reason, $error);

    expect($exception)->toBeInstanceOf(RollbackException::class)
        ->and($exception->getMessage())->toContain('Rollback execution failed')
        ->and($exception->getTaskId())->toBe($taskId)
        ->and($exception->getRollbackReason())->toBe($reason)
        ->and($exception->getContext())->toHaveKey('execution_error')
        ->and($exception->getContext()['execution_error'])->toBe($error);
});

test('dependencyFailed static method creates exception with dependencies', function () {
    $taskId = 'task-101';
    $dependencies = ['service1', 'service2'];

    $exception = RollbackException::dependencyFailed($taskId, $dependencies);

    expect($exception)->toBeInstanceOf(RollbackException::class)
        ->and($exception->getMessage())->toBe('Rollback dependencies not satisfied')
        ->and($exception->getTaskId())->toBe($taskId)
        ->and($exception->getRollbackReason())->toBe('dependency_failed')
        ->and($exception->getContext())->toHaveKey('dependencies')
        ->and($exception->getContext()['dependencies'])->toBe($dependencies);
});

test('safetyCheckFailed static method creates exception with safety check details', function () {
    $taskId = 'task-202';
    $check = 'backup_verification';
    $reason = 'Backup file not found';

    $exception = RollbackException::safetyCheckFailed($taskId, $check, $reason);

    expect($exception)->toBeInstanceOf(RollbackException::class)
        ->and($exception->getMessage())->toContain('Rollback safety check failed')
        ->and($exception->getMessage())->toContain($check)
        ->and($exception->getMessage())->toContain($reason)
        ->and($exception->getTaskId())->toBe($taskId)
        ->and($exception->getRollbackReason())->toBe('safety_check_failed')
        ->and($exception->getContext())->toHaveKey('failed_check')
        ->and($exception->getContext())->toHaveKey('check_reason')
        ->and($exception->getContext()['failed_check'])->toBe($check)
        ->and($exception->getContext()['check_reason'])->toBe($reason);
});

test('getTaskId returns null by default', function () {
    $exception = new RollbackException;

    expect($exception->getTaskId())->toBeNull();
});

test('getRollbackReason returns null by default', function () {
    $exception = new RollbackException;

    expect($exception->getRollbackReason())->toBeNull();
});

test('getContext returns empty array by default', function () {
    $exception = new RollbackException;

    expect($exception->getContext())->toBe([]);
});

test('RollbackException has correct namespace', function () {
    $exception = new RollbackException;

    expect($exception)->toBeInstanceOf(RollbackException::class);
});

test('validationFailed includes all validation errors in message', function () {
    $taskId = 'task-303';
    $validationErrors = ['email' => 'Invalid email', 'password' => 'Too short'];

    $exception = RollbackException::validationFailed($taskId, $validationErrors);

    expect($exception->getMessage())->toContain('Invalid email')
        ->and($exception->getMessage())->toContain('Too short');
});

test('executionFailed includes error in message', function () {
    $taskId = 'task-404';
    $reason = 'permission_denied';
    $error = 'Access denied to rollback directory';

    $exception = RollbackException::executionFailed($taskId, $reason, $error);

    expect($exception->getMessage())->toContain($error);
});

test('can access all properties correctly', function () {
    $message = 'Test rollback exception';
    $taskId = 'test-task-505';
    $rollbackReason = 'test_reason';
    $context = ['test_key' => 'test_value'];

    $exception = new RollbackException($message, 0, null, $taskId, $rollbackReason, $context);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getTaskId())->toBe($taskId)
        ->and($exception->getRollbackReason())->toBe($rollbackReason)
        ->and($exception->getContext())->toBe($context);
});

test('static factory methods create exceptions with correct structure', function () {
    $taskId = 'task-606';

    $validationException = RollbackException::validationFailed($taskId, []);
    $executionException = RollbackException::executionFailed($taskId, 'test', 'test error');
    $dependencyException = RollbackException::dependencyFailed($taskId, []);
    $safetyException = RollbackException::safetyCheckFailed($taskId, 'test', 'test reason');

    expect($validationException->getRollbackReason())->toBe('validation_failed')
        ->and($executionException->getRollbackReason())->toBe('test')
        ->and($dependencyException->getRollbackReason())->toBe('dependency_failed')
        ->and($safetyException->getRollbackReason())->toBe('safety_check_failed');
});
