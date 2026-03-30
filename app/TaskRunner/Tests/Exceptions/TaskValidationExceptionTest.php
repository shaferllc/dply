<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Exceptions\TaskValidationException;

test('can create TaskValidationException with default constructor', function () {
    $exception = new TaskValidationException;

    expect($exception)->toBeInstanceOf(TaskValidationException::class)
        ->and($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('Task validation failed.')
        ->and($exception->getCode())->toBe(0)
        ->and($exception->getErrors())->toBe([]);
});

test('can create TaskValidationException with custom message', function () {
    $message = 'Custom validation error message';
    $exception = new TaskValidationException($message);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getErrors())->toBe([]);
});

test('can create TaskValidationException with validation errors', function () {
    $errors = ['field1' => 'Field 1 is required', 'field2' => 'Field 2 must be valid'];
    $exception = new TaskValidationException('Validation failed', $errors);

    expect($exception->getMessage())->toBe('Validation failed')
        ->and($exception->getErrors())->toBe($errors);
});

test('can create TaskValidationException with all parameters', function () {
    $message = 'Complete validation error';
    $errors = ['name' => 'Name is required'];
    $code = 4001;
    $previousException = new Exception('Previous error');

    $exception = new TaskValidationException($message, $errors, $code, $previousException);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getErrors())->toBe($errors)
        ->and($exception->getCode())->toBe($code)
        ->and($exception->getPrevious())->toBe($previousException);
});

test('withErrors static method creates exception with errors', function () {
    $errors = ['email' => 'Email must be valid', 'password' => 'Password too short'];
    $message = 'Custom validation message';

    $exception = TaskValidationException::withErrors($errors, $message);

    expect($exception)->toBeInstanceOf(TaskValidationException::class)
        ->and($exception->getMessage())->toBe($message)
        ->and($exception->getErrors())->toBe($errors);
});

test('withErrors static method uses default message when not provided', function () {
    $errors = ['field' => 'Field error'];

    $exception = TaskValidationException::withErrors($errors);

    expect($exception->getMessage())->toBe('Task validation failed.')
        ->and($exception->getErrors())->toBe($errors);
});

test('getErrors returns empty array by default', function () {
    $exception = new TaskValidationException;

    expect($exception->getErrors())->toBe([]);
});

test('getErrors returns provided validation errors', function () {
    $errors = [
        'username' => 'Username is required',
        'email' => 'Email format is invalid',
        'age' => 'Age must be a number',
    ];

    $exception = new TaskValidationException('Validation failed', $errors);

    expect($exception->getErrors())->toBe($errors);
});

test('TaskValidationException has correct namespace', function () {
    $exception = new TaskValidationException;

    expect($exception)->toBeInstanceOf(TaskValidationException::class);
});

test('can create exception with complex validation errors', function () {
    $errors = [
        'user' => [
            'name' => 'Name is required',
            'email' => 'Email is invalid',
        ],
        'settings' => [
            'theme' => 'Theme must be one of: light, dark',
        ],
    ];

    $exception = new TaskValidationException('Complex validation failed', $errors);

    expect($exception->getErrors())->toBe($errors);
});

test('withErrors method can handle empty errors array', function () {
    $exception = TaskValidationException::withErrors([]);

    expect($exception->getErrors())->toBe([]);
});

test('can access all properties correctly', function () {
    $message = 'Test validation exception';
    $errors = ['test_field' => 'Test field error'];
    $code = 5001;

    $exception = new TaskValidationException($message, $errors, $code);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getErrors())->toBe($errors)
        ->and($exception->getCode())->toBe($code);
});

test('static factory method creates identical exception to constructor', function () {
    $message = 'Factory test';
    $errors = ['factory_field' => 'Factory error'];

    $constructorException = new TaskValidationException($message, $errors);
    $factoryException = TaskValidationException::withErrors($errors, $message);

    expect($constructorException->getMessage())->toBe($factoryException->getMessage())
        ->and($constructorException->getErrors())->toBe($factoryException->getErrors())
        ->and($constructorException->getCode())->toBe($factoryException->getCode());
});
