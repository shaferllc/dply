<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Exceptions\CouldNotCreateScriptDirectoryException;
use App\Modules\TaskRunner\ProcessOutput;

test('can create CouldNotCreateScriptDirectoryException with constructor', function () {
    $output = ProcessOutput::make('mkdir failed')->setExitCode(1);
    $message = 'Could not create script directory';
    $exception = new CouldNotCreateScriptDirectoryException($message, $output);

    expect($exception)->toBeInstanceOf(CouldNotCreateScriptDirectoryException::class)
        ->and($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe($message)
        ->and($exception->output)->toBe($output);
});

test('fromProcessOutput static method appends process output to message', function () {
    $output = ProcessOutput::make('Permission denied')->setExitCode(1);
    $exception = CouldNotCreateScriptDirectoryException::fromProcessOutput($output);

    expect($exception)->toBeInstanceOf(CouldNotCreateScriptDirectoryException::class)
        ->and($exception->getMessage())->toBe('Could not create script directory: Permission denied')
        ->and($exception->output)->toBe($output);
});

test('can create exception with custom message', function () {
    $output = ProcessOutput::make('Directory exists')->setExitCode(1);
    $message = 'Custom script directory error';
    $exception = new CouldNotCreateScriptDirectoryException($message, $output);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->output)->toBe($output);
});

test('CouldNotCreateScriptDirectoryException has correct namespace', function () {
    $output = ProcessOutput::make('test')->setExitCode(1);
    $exception = new CouldNotCreateScriptDirectoryException('Test error', $output);

    expect($exception)->toBeInstanceOf(CouldNotCreateScriptDirectoryException::class);
});

test('can access ProcessOutput properties from exception', function () {
    $buffer = 'mkdir: cannot create directory';
    $exitCode = 1;

    $output = ProcessOutput::make($buffer)->setExitCode($exitCode);
    $exception = new CouldNotCreateScriptDirectoryException('Directory creation failed', $output);

    expect($exception->output->getBuffer())->toBe($buffer)
        ->and($exception->output->getExitCode())->toBe($exitCode);
});

test('static factory method matches constructor when process buffer is empty', function () {
    $output = ProcessOutput::make('')->setExitCode(1);

    $constructorException = new CouldNotCreateScriptDirectoryException('Could not create script directory', $output);
    $factoryException = CouldNotCreateScriptDirectoryException::fromProcessOutput($output);

    expect($constructorException->getMessage())->toBe($factoryException->getMessage())
        ->and($constructorException->output)->toBe($factoryException->output);
});

test('can create exception with successful ProcessOutput', function () {
    $output = ProcessOutput::make('success')->setExitCode(0);
    $exception = new CouldNotCreateScriptDirectoryException('Unexpected failure', $output);

    expect($exception->output->getExitCode())->toBe(0)
        ->and($exception->output->isSuccessful())->toBeTrue();
});

test('output property is readonly', function () {
    $output = ProcessOutput::make('test')->setExitCode(1);
    $exception = new CouldNotCreateScriptDirectoryException('Test error', $output);

    expect($exception->output)->toBe($output);
});

test('can create exception with empty buffer', function () {
    $output = ProcessOutput::make('')->setExitCode(1);
    $exception = new CouldNotCreateScriptDirectoryException('Empty output error', $output);

    expect($exception->output->getBuffer())->toBe('');
});

test('can access all properties correctly', function () {
    $message = 'Test script directory exception';
    $buffer = 'Test output content';
    $exitCode = 1;

    $output = ProcessOutput::make($buffer)->setExitCode($exitCode);
    $exception = new CouldNotCreateScriptDirectoryException($message, $output);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->output->getBuffer())->toBe($buffer)
        ->and($exception->output->getExitCode())->toBe($exitCode);
});
