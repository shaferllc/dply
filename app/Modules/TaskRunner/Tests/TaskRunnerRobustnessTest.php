<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests;

use App\Modules\TaskRunner\Connection;
use App\Modules\TaskRunner\Exceptions\ConnectionNotFoundException;
use App\Modules\TaskRunner\Exceptions\TaskValidationException;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\Helper;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\Task;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Enable logging for tests
    config(['task-runner.logging.enabled' => true]);
});

test('it validates task properties', function () {
    $task = new class extends Task
    {
        public string $invalidProperty;

        public function __construct()
        {
            parent::__construct();
            $this->invalidProperty = str_repeat('a', 10001); // Too long
        }

        public function render(): string
        {
            return 'echo "test"';
        }
    };

    expect(fn () => $task->validate())
        ->toThrow(TaskValidationException::class, 'Property \'invalidProperty\' value is too long');
});

test('it validates script content for dangerous patterns', function () {
    $task = new class extends Task
    {
        public function render(): string
        {
            return 'rm -rf /'; // Forbidden command
        }
    };

    expect(fn () => $task->getScript())
        ->toThrow(TaskValidationException::class, 'Script contains forbidden command');
});

test('it validates script size', function () {
    $task = new class extends Task
    {
        public function render(): string
        {
            return str_repeat('echo "test";', 100000); // Very large script
        }
    };

    expect(fn () => $task->getScript())
        ->toThrow(TaskValidationException::class, 'Script is too large');
});

test('it handles empty scripts', function () {
    $task = new class extends Task
    {
        public function render(): string
        {
            return '';
        }
    };

    expect(fn () => $task->getScript())
        ->toThrow(TaskValidationException::class, 'Generated script is empty');
});

test('it validates timeout values', function () {
    $task = new class extends Task
    {
        public ?int $timeout = 0; // Invalid timeout

        public function render(): string
        {
            return 'echo "test"';
        }
    };

    expect(fn () => $task->validate())
        ->toThrow(TaskValidationException::class, 'Timeout must be between 1 and 3600 seconds');
});

test('it validates view existence', function () {
    $task = new class extends Task
    {
        public string $view = 'nonexistent-view';

        public function getView(): string
        {
            return $this->view;
        }
    };

    expect(fn () => $task->validate())
        ->toThrow(TaskValidationException::class, "View '{$task->getView()}' does not exist");
});

test('it handles retry logic for failed tasks', function () {
    TaskRunner::fake([
        'App\Tasks\FailingTask' => ProcessOutput::make('Failed')->setExitCode(1),
    ]);

    $task = new class extends Task
    {
        public function render(): string
        {
            return 'exit 1'; // Will fail
        }
    };

    $result = TaskRunner::run($task->pending());

    expect($result->isSuccessful())->toBeFalse();
    expect($result->getExitCode())->toBe(1);
});

test('it validates connection parameters', function () {
    expect(fn () => new Connection(
        host: '', // Empty host
        port: 22,
        username: 'test',
        privateKey: 'invalid-key',
        scriptPath: '/tmp/scripts'
    ))->toThrow(\InvalidArgumentException::class, 'Connection validation failed');
});

test('it validates private key format', function () {
    expect(fn () => new Connection(
        host: 'example.com',
        port: 22,
        username: 'test',
        privateKey: 'invalid-key-format',
        scriptPath: '/tmp/scripts'
    ))->toThrow(\InvalidArgumentException::class, 'The private key format is invalid');
});

test('it handles secure file operations', function () {
    $content = 'echo "test"';
    $tempFile = Helper::createSecureTempFile($content);

    expect(file_exists($tempFile))->toBeTrue();
    expect(file_get_contents($tempFile))->toBe($content);

    // Check permissions (should be 0700)
    $perms = fileperms($tempFile) & 0777;
    expect($perms)->toBe(0700);

    // Cleanup
    Helper::safeRemoveFile($tempFile);
    expect(file_exists($tempFile))->toBeFalse();
});

test('it validates path security', function () {
    expect(fn () => Helper::temporaryDirectoryPath('../../../etc/passwd'))
        ->toThrow(\InvalidArgumentException::class, 'Path contains invalid characters');
});

test('it handles pending task validation', function () {
    $task = new class extends Task
    {
        public function render(): string
        {
            return 'echo "test"';
        }
    };

    $pendingTask = $task->pending();

    expect(fn () => $pendingTask->id('')) // Empty ID
        ->toThrow(\InvalidArgumentException::class, 'Task ID cannot be empty');
});

test('it validates output paths', function () {
    $task = new class extends Task
    {
        public function render(): string
        {
            return 'echo "test"';
        }
    };

    $pendingTask = $task->pending();

    expect(fn () => $pendingTask->writeOutputTo('/etc/passwd')) // Dangerous path
        ->toThrow(\InvalidArgumentException::class, 'Invalid output path: contains path traversal characters');
});

test('it handles connection not found', function () {
    expect(fn () => Connection::fromConfig('nonexistent'))
        ->toThrow(ConnectionNotFoundException::class, 'Connection `nonexistent` not found');
});

test('it logs execution details', function () {
    Log::shouldReceive('log')
        ->with('info', 'Process executed', \Mockery::type('array'))
        ->once();

    TaskRunner::fake([
        'App\Tasks\TestTask' => ProcessOutput::make('Success')->setExitCode(0),
    ]);

    $task = new class extends Task
    {
        public function render(): string
        {
            return 'echo "success"';
        }
    };

    TaskRunner::run($task->pending());
});

test('it handles configuration validation', function () {
    // Test with invalid configuration
    config(['task-runner.temporary_directory' => '/nonexistent/path']);

    expect(fn () => Helper::temporaryDirectory())
        ->toThrow(\InvalidArgumentException::class, 'Temporary directory is not writable');
});

test('it validates script content', function () {
    $validScript = 'echo "test"';
    $result = Helper::validateScriptContent($validScript);
    expect($result)->toBe($validScript);

    expect(fn () => Helper::validateScriptContent(''))
        ->toThrow(\InvalidArgumentException::class);
});

test('it handles background execution', function () {
    $task = new class extends Task
    {
        public function render(): string
        {
            return 'sleep 5 && echo "done"';
        }
    };

    $pendingTask = $task->pending()
        ->inBackground()
        ->writeOutputTo('/tmp/test.log');

    expect($pendingTask->shouldRunInBackground())->toBeTrue();
    expect($pendingTask->getOutputPath())->toBe('/tmp/test.log');
});

test('it provides comprehensive error messages', function () {
    $task = new class extends Task
    {
        public function render(): string
        {
            throw new \Exception('Script generation failed');
        }
    };

    expect(fn () => $task->getScript())
        ->toThrow(TaskValidationException::class, 'Failed to generate script: Script generation failed');
});
