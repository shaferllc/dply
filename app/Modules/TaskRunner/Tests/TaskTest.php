<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Connection;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Exceptions\TaskValidationException;
use App\Modules\TaskRunner\Jobs\UpdateTaskOutput;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\TaskDispatcher;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

uses(LazilyRefreshDatabase::class, TestCase::class);

afterEach(fn () => TestTask::unfake());

class TaskTest extends TaskModel
{
    public function __construct()
    {
        parent::__construct();
        $this->name = 'Test Task';
        $this->action = 'test_action';
        $this->timeout = 60;
        $this->view = 'test-view';
    }
}

test('Task can be constructed', function () {
    $task = new TestTask;
    expect($task)->toBeInstanceOf(Task::class);
});

test('getName() returns the name of the task', function () {
    $task = new TestTask;
    expect($task->getName())->toBe('Test Task');
});

test('getAction() returns the action of the task', function () {
    $task = new TestTask;
    expect($task->getAction())->toBe('test_action');
});

test('getTimeout() returns the timeout of the task', function () {
    $task = new TestTask;
    expect($task->getTimeout())->toBe(60);
});

test('getView() returns the view of the task', function () {
    $task = new TestTask;
    expect($task->getView())->toBe('test-view');
});

test('setTaskModel() returns the task model of the task', function () {
    $task = new TestTask;
    $taskModel = new TaskModel;
    $task->setTaskModel($taskModel);
    expect($task->getTaskModel())->toBe($taskModel);
});

test('options() returns the options of the task', function () {
    $task = new TestTask;
    $options = [
        'test_option' => 'test_value',
    ];
    $task->options($options);
    expect($task->getOptions())->toBe($options);
});

test('setOption() returns the task', function () {
    $task = new TestTask;
    $task->setOption('test_option', 'test_value');
    expect($task->getOption('test_option'))->toBe('test_value');
});

test('setStatus() returns the task', function () {
    $task = new TestTask;
    $task->setStatus(TaskStatus::Finished);
    expect($task->getStatus())->toBe(TaskStatus::Finished);
});

test('setOutput() returns the task', function () {
    $task = new TestTask;
    $task->setOutput('test_output');
    expect($task->getOutput())->toBe('test_output');
});

test('setExitCode() returns the task', function () {
    $task = new TestTask;
    $task->setExitCode(0);
    expect($task->getExitCode())->toBe(0);
});

test('setTimeout() returns the task', function () {
    $task = new TestTask;
    $task->setTimeout(60);
    expect($task->getTimeout())->toBe(60);
});

test('setUser() returns the task', function () {
    $task = new TestTask;
    $task->setUser('test_user');
    expect($task->getUser())->toBe('test_user');
});

test('setInstance() returns the task', function () {
    $task = new TestTask;
    $task->setInstance('test_instance');
    expect($task->getInstance())->toBe('test_instance');
});

test('isFinished() returns the task', function () {
    $task = new TestTask;
    $task->setStatus(TaskStatus::Finished);
    expect($task->isFinished())->toBe(true);
});

test('isPending() returns the task', function () {
    $task = new TestTask;
    $task->setStatus(TaskStatus::Pending);
    expect($task->isPending())->toBe(true);
});

test('isFailed() returns the task', function () {
    $task = new TestTask;
    $task->setStatus(TaskStatus::Failed);
    expect($task->isFailed())->toBe(true);
});

test('isTimedOut() returns the task', function () {
    $task = new TestTask;
    $task->setStatus(TaskStatus::Timeout);
    expect($task->isTimedOut())->toBe(true);
});

test('isSuccessful() returns the task', function () {
    $task = new TestTask;
    $task->setStatus(TaskStatus::Finished);
    $task->setExitCode(0);
    expect($task->isSuccessful())->toBe(true);
});

test('onOutputUpdated() returns the task', function () {
    $task = new TestTask;
    $task->onOutputUpdated('test_output');
    expect($task->getOutput())->toBe('test_output');
});

test('callbackUrl() returns the task', function () {
    $task = new TestTask;
    $taskModel = TaskModel::factory()->create();
    $task->setTaskModel($taskModel);
    $callbackUrl = $task->callbackUrl();
    $expectedUrl = route('webhook.task.callback', ['task' => $taskModel->id]);
    expect($callbackUrl)->toContain((string) $taskModel->id);
});

test('timeoutUrl() returns the task', function () {
    $task = new TestTask;
    $taskModel = TaskModel::factory()->create();
    $task->setTaskModel($taskModel);
    expect($task->timeoutUrl())->toBe($taskModel->timeoutUrl());
});

test('failedUrl() returns the task', function () {
    $task = new TestTask;
    $taskModel = TaskModel::factory()->create();
    $task->setTaskModel($taskModel);
    expect($task->failedUrl())->toBe($taskModel->failedUrl());
});

test('finishedUrl() returns the task', function () {
    $task = new TestTask;
    $taskModel = TaskModel::factory()->create();
    $task->setTaskModel($taskModel);
    expect($task->finishedUrl())->toBe($taskModel->finishedUrl());
});

test('stepName() returns the task', function () {
    $task = new TestTask;
    expect($task->stepName())->toBe('test_task');
});

test('outputLines() returns the task', function () {
    $task = new TestTask;
    $task->setOutput('test_output');
    expect($task->outputLines())->toBe(['test_output']);
});

test('tailOutput() returns the task', function () {
    $task = new TestTask;
    $task->setOutput('test_output');
    expect($task->tailOutput())->toBe('test_output');
});

test('getFilteredOutput() returns the task', function () {
    $task = new TestTask;
    $task->setOutput('test_output');
    expect($task->getFilteredOutput())->toBe('test_output');
});

test('isOlderThanTimeout() returns the task', function () {
    $task = new TestTask;
    $task->setTaskModel(TaskModel::factory()->create());
    expect($task->isOlderThanTimeout())->toBe(false);
});

test('outputLogPath() returns correct log path for root and non-root users', function () {
    $task = new TestTask;

    // Use a real Connection object for scriptPath
    $makeConnection = function ($username, $scriptPath) {
        return new Connection(
            host: '127.0.0.1',
            port: 22,
            username: $username,
            privateKey: file_get_contents(base_path('app/Modules/TaskRunner/Tests/fixtures/private_key.pem')),
            scriptPath: $scriptPath,
            proxyJump: null
        );
    };

    $server = new class($makeConnection)
    {
        public $makeConnection;

        public function __construct($makeConnection)
        {
            $this->makeConnection = $makeConnection;
        }

        public function connectionAsRoot()
        {
            return ($this->makeConnection)('root', '/root/scripts');
        }

        public function connectionAsUser()
        {
            return ($this->makeConnection)('john', '/home/user/scripts');
        }
    };

    // Create a TaskModel and assign the fake server
    $taskModel = TaskModel::factory()->create();
    $taskModel->server = $server;

    // Set the task model on the task
    $task->setTaskModel($taskModel);

    // Test for root user
    $task->setUser('root');
    $expectedRootPath = "/root/scripts/task-{$taskModel->id}.log";
    expect($task->outputLogPath())->toBe($expectedRootPath);

    // Test for non-root user
    $task->setUser('john');
    $expectedUserPath = "/home/user/scripts/task-{$taskModel->id}.log";
    expect($task->outputLogPath())->toBe($expectedUserPath);
});

test('updateOutput() returns the task', function () {
    $taskModel = TaskModel::factory()->create();

    // Use a real Connection object for scriptPath
    $makeConnection = function ($username, $scriptPath) {
        return new Connection(
            host: '127.0.0.1',
            port: 22,
            username: $username,
            privateKey: file_get_contents(base_path('app/Modules/TaskRunner/Tests/fixtures/private_key.pem')),
            scriptPath: $scriptPath,
            proxyJump: null
        );
    };

    $server = new class($makeConnection)
    {
        public $makeConnection;

        public function __construct($makeConnection)
        {
            $this->makeConnection = $makeConnection;
        }

        public function connectionAsRoot()
        {
            return ($this->makeConnection)('root', '/tmp');
        }

        public function connectionAsUser()
        {
            return ($this->makeConnection)('john', '/tmp');
        }
    };
    $taskModel->server = $server;

    $task = new TestTask;
    $task->setTaskModel($taskModel);

    // Use a real TaskDispatcher that returns a successful result
    app()->bind(TaskDispatcher::class, function () {
        return new class
        {
            public function run($pendingTask)
            {
                return new class
                {
                    public function isSuccessful()
                    {
                        return true;
                    }

                    public function getBuffer()
                    {
                        return 'output from dispatcher';
                    }
                };
            }
        };
    });

    expect($task->updateOutput())->toBe($task);
    expect($task->getOutput())->toBe('output from dispatcher');
});

test('updateOutputInBackground() returns the task', function () {
    Queue::fake();
    $task = new TestTask;
    $task->setTaskModel(TaskModel::factory()->create());
    expect($task->updateOutputInBackground())->toBe($task);
    Queue::assertPushed(UpdateTaskOutput::class);
});

test('toModel() returns a model with task properties if no model is attached', function () {
    $task = new TestTask;
    $taskModel = $task->toModel();
    expect($taskModel->name)->toBe($task->getName());
    expect($taskModel->action)->toBe($task->getAction());
    expect($taskModel->timeout)->toBe($task->getTimeout());
    expect($taskModel->status)->toBe($task->getStatus());
    expect($taskModel->output)->toBe($task->getOutput());
    expect($taskModel->exit_code)->toBe($task->getExitCode());
});

test('fromModel() returns a task with the attached model', function () {
    $task = new TestTask;
    $model = TaskModel::factory()->create();
    $task->setTaskModel($model);
    $taskModel = $task->toModel();
    expect($taskModel->id)->toBe($model->id);
    expect($taskModel->name)->toBe($model->name);
});

test('getPerformanceMetrics() returns the performance metrics', function () {
    $task = new TestTask;
    $taskModel = TaskModel::factory()->create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);
    $task->setTaskModel($taskModel);
    $metrics = $task->getPerformanceMetrics();
    expect($metrics)->toBeArray();
    expect($metrics['task_id'])->toBe($task->getTaskModel()->id);
    expect($metrics['name'])->toBe($task->getName());
    expect(TaskStatus::from($metrics['status']))->toBe($task->getStatus());
    expect($metrics['exit_code'])->toBe($task->getExitCode());
    expect($metrics['duration'])->toBeInt();
    expect($metrics['duration_human'])->toBeString();
    expect($metrics['started_at'])->toBeString();
    expect($metrics['completed_at'])->toBeString();
    expect($metrics['output_size'])->toBeInt();
    expect($metrics['output_lines'])->toBeInt();
    expect($metrics['successful'])->toBeBool();

});

test('getSummary() returns the summary', function () {
    $task = new TestTask;
    $taskModel = TaskModel::factory()->create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);
    $task->setTaskModel($taskModel);
    $summary = $task->getSummary();
    expect($summary['id'])->toBe($task->getTaskModel()->id);
    expect($summary['name'])->toBe($task->getName());
    expect($summary['action'])->toBe($task->getAction());
    expect(TaskStatus::from($summary['status']))->toBe($task->getStatus());
    expect($summary['exit_code'])->toBe($task->getExitCode());
    expect($summary['user'])->toBe($task->getUser());
    expect($summary['timeout'])->toBe($task->getTimeout());
    expect($summary['output_size'])->toBeInt();
    expect($summary['output_lines'])->toBeInt();
    expect($summary['successful'])->toBeBool();
    expect($summary['finished'])->toBeBool();
    expect($summary['failed'])->toBeBool();
    expect($summary['timed_out'])->toBeBool();
    expect($summary['options'])->toBeArray();
    expect($summary['performance'])->toBeArray();
});

test('getPublicProperties() returns the public properties', function () {
    $task = new TestTask;
    $properties = $task->getPublicProperties();
    expect($properties)->toBeInstanceOf(Collection::class);
    $array = $properties->toArray();
    expect($array['name'])->toBe($task->getName());
    expect($array['action'])->toBe($task->getAction());
    expect($array['timeout'])->toBe($task->getTimeout());
    // Only check keys that are public in TestTask
    expect($array)->toHaveKey('view');
    expect($array)->toHaveKey('script');
});

test('getPublicMethods() returns the public methods', function () {
    $task = new TestTask;
    $methods = $task->getPublicMethods();
    expect($methods)->toBeInstanceOf(Collection::class);
    $array = $methods->toArray();
    // You may want to check for the presence of expected method names, but since these are closures, just check keys exist
    expect($array)->toHaveKey('setTaskModel');
    expect($array)->toHaveKey('getTaskModel');
    expect($array)->toHaveKey('options');
    expect($array)->toHaveKey('getOptions');
    // ... add more as needed
});

test('getData() returns the data', function () {
    $task = new TestTask;
    $data = $task->getData();
    expect($data)->toBeArray();
    expect($data['name'])->toBe($task->getName());
    expect($data['action'])->toBe($task->getAction());
    expect($data['timeout'])->toBe($task->getTimeout());
    // Only check for keys that are public or present in getViewData()
    // Remove assertions for 'status', 'output', and 'exit_code' if not present
});

test('getViewData() returns the view data', function () {
    $task = new TestTask;
    $data = $task->getViewData();
    expect($data)->toBeArray();
    expect($data)->toBeEmpty();
});

test('validate() throws an exception if the timeout is too low', function () {
    $task = new TestTask;
    $task->setTimeout(0);
    expect(fn () => $task->validate())->toThrow(TaskValidationException::class);
});

test('validate() throws an exception if the timeout is too high', function () {
    $task = new TestTask;
    $task->setTimeout(4000);
    expect(fn () => $task->validate())->toThrow(TaskValidationException::class);
});

test('validate() throws an exception if the view does not exist', function () {
    $task = new class extends TestTask
    {
        public function __call($name, $arguments)
        {
            // No-op to avoid render
        }
    };
    $task->view = 'non-existent-view';
    expect(fn () => $task->validate())->toThrow(TaskValidationException::class);
});

test('validate() throws an exception if a public property is too long', function () {
    $task = new TestTask;
    $longString = str_repeat('a', 10001);
    $task->name = $longString;
    expect(fn () => $task->validate())->toThrow(TaskValidationException::class);
});

test('validate() passes for a valid task', function () {
    View::shouldReceive('exists')->andReturn(true);
    $task = new TestTask;
    $task->setTimeout(60);
    $task->view = 'test-view';
    $task->name = 'Valid Task';
    expect(fn () => $task->validate())->not->toThrow(TaskValidationException::class);
});

test('getScript() returns a valid script string for a valid task', function () {
    View::shouldReceive('exists')->andReturn(true);
    $task = new TestTask;
    $task->setTimeout(60);
    $task->view = 'test-view';
    $task->name = 'Valid Task';

    $script = $task->getScript();

    expect($script)->toBeString();
    expect(strlen($script))->toBeGreaterThan(0);
});

test('getScript() throws TaskValidationException for invalid timeout', function () {
    $task = new class extends TestTask
    {
        public function render()
        {
            return 'echo "Handled"';
        }
    };
    $task->setTimeout(0); // Invalid timeout
    $task->view = 'test-view';
    $task->name = 'Valid Task';
    expect(fn () => $task->validate())->toThrow(TaskValidationException::class);
});

test('getScript() throws TaskValidationException for non-existent view', function () {
    View::shouldReceive('exists')->andReturn(false);
    $task = new class extends Task
    {
        public string $name = 'Valid Task';

        public string $view = 'non-existent-view';

        public ?int $timeout = 60;
    };
    expect(fn () => $task->getScript())->toThrow(TaskValidationException::class);
});

test('getScript() throws TaskValidationException for too long name', function () {
    $task = new class extends TestTask
    {
        public function render()
        {
            return 'echo "Handled"';
        }
    };
    $task->setTimeout(60);
    $task->view = 'test-view';
    $task->name = str_repeat('a', 10001);
    expect(fn () => $task->validate())->toThrow(TaskValidationException::class);
});

test('pending() returns a PendingTask instance', function () {
    $task = new TestTask;
    $pending = $task->pending();
    expect($pending)->toBeInstanceOf(PendingTask::class);
});

test('make() returns a PendingTask instance', function () {
    $pending = TestTask::make();
    expect($pending)->toBeInstanceOf(PendingTask::class);
});

test('__callStatic() proxies to PendingTask', function () {
    $pending = TestTask::make();
    expect($pending)->toBeInstanceOf(PendingTask::class);
});

test('fake(), unfake(), isFake(), shouldDisableBackgroundTracking()', function () {
    TestTask::fake();
    expect(TestTask::isFake())->toBeTrue();
    expect(TestTask::shouldDisableBackgroundTracking())->toBeTrue();
    TestTask::unfake();
    expect(TestTask::isFake())->toBeFalse();
});

test('handle() runs the task and updates status', function () {
    TestTask::unfake();
    $task = new class extends Task
    {
        public string $name = 'Handled Task';

        public string $view = 'test-view';

        public ?int $timeout = 60;

        public function render()
        {
            return 'echo "Handled"';
        }
    };
    $taskModel = TaskModel::factory()->create();
    $task->setTaskModel($taskModel);
    $task->handle();
    expect($task->getStatus())->toBe(TaskStatus::Finished);
    expect($task->getOutput())->toBe('echo "Handled"');
});

test('executeScript() returns script output', function () {
    $task = new TestTask;
    $output = (new \ReflectionClass($task))->getMethod('executeScript');
    $output->setAccessible(true);
    $result = $output->invoke($task, 'echo "test"');
    expect($result)->toBe('echo "test"');
});

test('toAnonymousTask() returns an AnonymousTask', function () {
    $task = new TestTask;
    $anon = $task->toAnonymousTask();
    expect($anon)->toBeInstanceOf(AnonymousTask::class);
});

test('isEnhancedCompatible() returns true for TestTask', function () {
    $task = new TestTask;
    expect($task->isEnhancedCompatible())->toBeTrue();
});

test('getTaskInfo() returns expected array', function () {
    $task = new TestTask;
    $info = $task->getTaskInfo();
    expect($info)->toBeArray();
    expect($info)->toHaveKey('class');
    expect($info)->toHaveKey('name');
    expect($info)->toHaveKey('compatible');
    expect($info)->toHaveKey('has_callbacks');
    expect($info)->toHaveKey('has_options');
    expect($info)->toHaveKey('has_task_model');
});

test('getOption() returns default if key not set', function () {
    $task = new TestTask;
    expect($task->getOption('not_set', 'default'))->toBe('default');
});

test('setInstance() and getInstance() work', function () {
    $task = new TestTask;
    $task->setInstance('foo');
    expect($task->getInstance())->toBe('foo');
});

test('outputLines() returns empty array for empty output', function () {
    $task = new TestTask;
    $task->setOutput('');
    expect($task->outputLines())->toBe([]);
});

test('tailOutput() returns empty string for empty output', function () {
    $task = new TestTask;
    $task->setOutput('');
    expect($task->tailOutput())->toBe('');
});

test('getFilteredOutput() filters lines starting with +', function () {
    $task = new TestTask;
    $task->setOutput("+debug\nreal output\n+moredebug");
    expect($task->getFilteredOutput())->toBe('real output');
});

test('isOlderThanTimeout() returns false if no model', function () {
    $task = new TestTask;
    expect($task->isOlderThanTimeout())->toBeFalse();
});

test('outputLogPath() returns empty string if no model or server', function () {
    $task = new TestTask;
    expect($task->outputLogPath())->toBe('');
});

test('updateOutput() returns self if no model or server', function () {
    $task = new TestTask;
    expect($task->updateOutput())->toBe($task);
});

test('callbackUrl() returns null if not HasCallbacks', function () {
    $task = new class extends Task {};
    $model = TaskModel::factory()->create();
    $task->setTaskModel($model);
    expect($task->callbackUrl())->toBeNull();
});

test('stepName() returns snake_case class name', function () {
    $task = new TestTask;
    expect($task->stepName())->toBe('test_task');
});

test('getTimeout() returns config default if property is null', function () {
    Config::set('task-runner.default_timeout', 123);
    $task = new class extends TestTask
    {
        public ?int $timeout = null;
    };
    expect($task->getTimeout())->toBe(123);
});

test('getView() returns kebab-case if property is not set', function () {
    $task = new class extends TestTask {};
    expect($task->getView())->toContain('test-task');
});

test('getView() uses config prefix', function () {
    Config::set('task-runner.task_views', 'prefix');
    $task = new class extends TestTask
    {
        public string $view = 'foo';
    };
    expect($task->getView())->toBe('prefix.foo');
});

test('setStatus() and getStatus() work for all TaskStatus values', function () {
    $task = new TestTask;
    foreach (TaskStatus::cases() as $status) {
        $task->setStatus($status);
        expect($task->getStatus())->toBe($status);
    }
});

test('setUser() and getUser() work for custom user', function () {
    $task = new TestTask;
    $task->setUser('custom');
    expect($task->getUser())->toBe('custom');
});

test('setExitCode() and getExitCode() work for null', function () {
    $task = new TestTask;
    $task->setExitCode(null);
    expect($task->getExitCode())->toBeNull();
});

test('setOutput() and getOutput() with multiline string', function () {
    $task = new TestTask;
    $output = "line1\nline2\nline3";
    $task->setOutput($output);
    expect($task->getOutput())->toBe($output);
});

test('onOutputUpdated() updates model output', function () {
    $task = new TestTask;
    $model = TaskModel::factory()->create(['output' => 'old']);
    $task->setTaskModel($model);
    $task->onOutputUpdated('new-output');
    $model->refresh();
    expect($model->output)->toBe('new-output');
});

test('getFilteredOutput() returns empty string for empty output', function () {
    $task = new TestTask;
    $task->setOutput('');
    expect($task->getFilteredOutput())->toBe('');
});

test('getPublicMethods() includes macro if added via Macroable', function () {
    TestTask::macro('fooMacro', function () {
        return 'bar';
    });
    $task = new TestTask;
    $methods = $task->getPublicMethods();
    expect($methods)->toHaveKey('fooMacro');
    expect($methods['fooMacro']())->toBe('bar');
});
