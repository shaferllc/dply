<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Exceptions\CouldNotUploadFileException;
use App\Modules\TaskRunner\ProcessOutput;

test('can create CouldNotUploadFileException with default constructor', function () {
    $output = ProcessOutput::make('test output')->setExitCode(1);
    $exception = new CouldNotUploadFileException($output);

    expect($exception)->toBeInstanceOf(CouldNotUploadFileException::class)
        ->and($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('Could not upload file')
        ->and($exception->output)->toBe($output);
});

test('can create CouldNotUploadFileException with custom message', function () {
    $output = ProcessOutput::make('test output')->setExitCode(1);
    $message = 'Custom upload error message';
    $exception = new CouldNotUploadFileException($output, $message);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->output)->toBe($output);
});

test('fromProcessOutput static method appends process output to message', function () {
    $output = ProcessOutput::make('upload failed')->setExitCode(1);
    $exception = CouldNotUploadFileException::fromProcessOutput($output);

    expect($exception)->toBeInstanceOf(CouldNotUploadFileException::class)
        ->and($exception->getMessage())->toBe('Could not upload file: upload failed')
        ->and($exception->output)->toBe($output);
});

test('CouldNotUploadFileException has correct namespace', function () {
    $output = ProcessOutput::make('test')->setExitCode(0);
    $exception = new CouldNotUploadFileException($output);

    expect($exception)->toBeInstanceOf(CouldNotUploadFileException::class);
});

test('can access ProcessOutput properties from exception', function () {
    $buffer = 'File upload output';
    $exitCode = 1;

    $output = ProcessOutput::make($buffer)->setExitCode($exitCode);
    $exception = new CouldNotUploadFileException($output, 'Upload failed');

    expect($exception->output->getBuffer())->toBe($buffer)
        ->and($exception->output->getExitCode())->toBe($exitCode);
});

test('static factory method matches constructor when process buffer is empty', function () {
    $output = ProcessOutput::make('')->setExitCode(1);

    $constructorException = new CouldNotUploadFileException($output);
    $factoryException = CouldNotUploadFileException::fromProcessOutput($output);

    expect($constructorException->getMessage())->toBe($factoryException->getMessage())
        ->and($constructorException->output)->toBe($factoryException->output);
});

test('can create exception with successful ProcessOutput', function () {
    $output = ProcessOutput::make('success')->setExitCode(0);
    $exception = new CouldNotUploadFileException($output, 'Unexpected failure');

    expect($exception->output->getExitCode())->toBe(0)
        ->and($exception->output->isSuccessful())->toBeTrue();
});

test('output property is readonly', function () {
    $output = ProcessOutput::make('test')->setExitCode(1);
    $exception = new CouldNotUploadFileException($output);

    expect($exception->output)->toBe($output);
});
