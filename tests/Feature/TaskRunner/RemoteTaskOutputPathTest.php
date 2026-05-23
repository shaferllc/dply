<?php

declare(strict_types=1);

namespace Tests\Feature\TaskRunner\RemoteTaskOutputPathTest;

use App\Models\Server;
use App\Modules\TaskRunner\Connection;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Jobs\UpdateTaskOutput;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\ProcessRunner;
use App\Modules\TaskRunner\RemoteProcessRunner;
use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\TaskDispatcher;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('task model uses persisted remote output path when present', function () {
    $taskModel = TaskModel::query()->create([
        'name' => 'Remote output path probe',
        'action' => 'probe',
        'script' => 'echo test',
        'timeout' => 300,
        'user' => 'root',
        'status' => TaskStatus::Running,
        'options' => [
            'remote_output_path' => '/root/.dply-task-runner/custom-remote.log',
        ],
    ]);

    $taskModel->server = new class
    {
        public function connectionAsRoot(): Connection
        {
            return new Connection(
                host: '127.0.0.1',
                port: 22,
                username: 'root',
                privateKey: file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
                scriptPath: '/root/.dply-task-runner',
                proxyJump: null,
            );
        }

        public function connectionAsUser(): Connection
        {
            return new Connection(
                host: '127.0.0.1',
                port: 22,
                username: 'deploy',
                privateKey: file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
                scriptPath: '/home/deploy/.dply-task-runner',
                proxyJump: null,
            );
        }
    };

    $this->assertSame('/root/.dply-task-runner/custom-remote.log', $taskModel->outputLogPath());
});
test('run on connection persists remote script and output paths on task model', function () {
    $task = new TestTask;

    $taskModel = TaskModel::query()->create([
        'name' => 'Remote dispatch probe',
        'action' => 'probe',
        'script' => 'echo test',
        'timeout' => 300,
        'user' => 'root',
        'status' => TaskStatus::Pending,
        'options' => [],
    ]);
    $task->setTaskModel($taskModel);

    $pendingTask = $task->pending()
        ->onConnection(new Connection(
            host: '127.0.0.1',
            port: 22,
            username: 'root',
            privateKey: file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
            scriptPath: '/root/.dply-task-runner',
            proxyJump: null,
        ))
        ->inBackground()
        ->withId('abc123');

    $dispatcher = new class(app(ProcessRunner::class)) extends TaskDispatcher
    {
        public function createRemoteRunner(PendingTask $pendingTask): RemoteProcessRunner
        {
            return new class($pendingTask->getConnection(), app(ProcessRunner::class)) extends RemoteProcessRunner
            {
                public function verifyScriptDirectoryExists(): self
                {
                    return $this;
                }

                public function upload($filename, $contents): self
                {
                    return $this;
                }

                public function runUploadedScriptInBackground(string $script, string $output, int $timeout = 0): ProcessOutput
                {
                    return ProcessOutput::make('ok')->setExitCode(0);
                }
            };
        }
    };

    $dispatcher->runOnConnection($pendingTask);

    $taskModel->refresh();

    $this->assertSame('/root/.dply-task-runner/abc123.sh', $taskModel->options['remote_script_path'] ?? null);
    $this->assertSame('/root/.dply-task-runner/abc123.log', $taskModel->options['remote_output_path'] ?? null);
});
test('run on connection uploads tracking wrapper for remote tracked tasks', function () {
    $trackedTask = new TrackTaskInBackground(
        new TestTask('Provision server'),
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    $taskModel = TaskModel::query()->create([
        'name' => 'Provision server',
        'action' => 'provision_stack',
        'script' => 'echo test',
        'timeout' => 300,
        'user' => 'root',
        'status' => TaskStatus::Pending,
        'options' => [],
    ]);
    $trackedTask->setTaskModel($taskModel);
    $trackedTask->actualTask->setTaskModel($taskModel);

    $pendingTask = $trackedTask->pending()
        ->onConnection(new Connection(
            host: '127.0.0.1',
            port: 22,
            username: 'root',
            privateKey: file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
            scriptPath: '/root/.dply-task-runner',
            proxyJump: null,
        ))
        ->inBackground()
        ->withId('tracked123');

    $dispatcher = new class(app(ProcessRunner::class)) extends TaskDispatcher
    {
        public ?string $uploadedFilename = null;

        public ?string $uploadedContents = null;

        public function createRemoteRunner(PendingTask $pendingTask): RemoteProcessRunner
        {
            return new class($pendingTask->getConnection(), app(ProcessRunner::class), $this) extends RemoteProcessRunner
            {
                public function __construct(Connection $connection, ProcessRunner $processRunner, private readonly object $testHarness)
                {
                    parent::__construct($connection, $processRunner);
                }

                public function verifyScriptDirectoryExists(): self
                {
                    return $this;
                }

                public function upload($filename, $contents): self
                {
                    $this->testHarness->uploadedFilename = $filename;
                    $this->testHarness->uploadedContents = $contents;

                    return $this;
                }

                public function runUploadedScriptInBackground(string $script, string $output, int $timeout = 0): ProcessOutput
                {
                    return ProcessOutput::make('ok')->setExitCode(0);
                }
            };
        }
    };
    $dispatcher->runOnConnection($pendingTask);

    $this->assertSame('tracked123.sh', $dispatcher->uploadedFilename);
    $this->assertStringContainsString('Task completed successfully, calling finished webhook...', (string) $dispatcher->uploadedContents);
    $this->assertStringContainsString('httpPost', (string) $dispatcher->uploadedContents);
});
test('remote process runner background command does not require local remote file', function () {
    $connection = new Connection(
        host: '127.0.0.1',
        port: 22,
        username: 'root',
        privateKey: file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
        scriptPath: '/root/.dply-task-runner',
        proxyJump: null,
    );

    $runner = new class($connection, app(ProcessRunner::class)) extends RemoteProcessRunner
    {
        public ?string $capturedScript = null;

        public function run(string $script, int $timeout = 0): ProcessOutput
        {
            $this->capturedScript = $script;

            return ProcessOutput::make('ok')->setExitCode(0);
        }
    };
    $runner->runUploadedScriptInBackground('task-abc123.sh', 'task-abc123.log', 300);

    $this->assertSame(
        'timeout 300s bash /root/.dply-task-runner/task-abc123.sh > /root/.dply-task-runner/task-abc123.log 2>&1 &',
        $runner->capturedScript,
    );
});
test('run in background with model persists remote paths for tracked remote tasks', function () {
    $server = Server::factory()->ready()->create([
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'root',
        'ssh_private_key' => file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
    ]);

    $taskModel = TaskModel::query()->create([
        'name' => 'Provision server',
        'action' => 'provision_stack',
        'script' => 'echo test',
        'timeout' => 300,
        'user' => 'root',
        'status' => TaskStatus::Pending,
        'server_id' => $server->id,
        'options' => [],
    ]);

    $trackedTask = new TrackTaskInBackground(
        new TestTask('Provision server'),
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    $dispatcher = new class(app(ProcessRunner::class)) extends TaskDispatcher
    {
        public function runOnConnection(PendingTask $pendingTask): ProcessOutput
        {
            return ProcessOutput::make('remote started')->setExitCode(0);
        }

        public function startBackgroundMonitoring(Task $task, TaskModel $taskModel): void {}
    };

    $dispatcher->runInBackgroundWithModel($trackedTask, $taskModel);

    $taskModel->refresh();

    $this->assertSame('/root/.dply-task-runner/task-'.$taskModel->id.'-original.sh', $taskModel->options['remote_script_path'] ?? null);
    $this->assertSame('/root/.dply-task-runner/task-'.$taskModel->id.'-original.sh.log', $taskModel->options['remote_output_path'] ?? null);
});
test('run in background with model does not start output polling for tracked remote tasks', function () {
    Queue::fake();

    $server = Server::factory()->ready()->create([
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'root',
        'ssh_private_key' => file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
    ]);

    $taskModel = TaskModel::query()->create([
        'name' => 'Provision server',
        'action' => 'provision_stack',
        'script' => 'echo test',
        'timeout' => 300,
        'user' => 'root',
        'status' => TaskStatus::Pending,
        'server_id' => $server->id,
        'options' => [],
    ]);

    $trackedTask = new TrackTaskInBackground(
        new TestTask('Provision server'),
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    $dispatcher = new class(app(ProcessRunner::class)) extends TaskDispatcher
    {
        public function runOnConnection(PendingTask $pendingTask): ProcessOutput
        {
            return ProcessOutput::make('remote started')->setExitCode(0);
        }
    };

    $dispatcher->runInBackgroundWithModel($trackedTask, $taskModel);

    Queue::assertNotPushed(UpdateTaskOutput::class);
});
test('run in background with model uses remote dispatch path for tracked remote tasks', function () {
    $server = Server::factory()->ready()->create([
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'root',
        'ssh_private_key' => file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
    ]);

    $taskModel = TaskModel::query()->create([
        'name' => 'Provision server',
        'action' => 'provision_stack',
        'script' => 'echo test',
        'timeout' => 300,
        'user' => 'root',
        'status' => TaskStatus::Pending,
        'server_id' => $server->id,
        'options' => [],
    ]);

    $trackedTask = new TrackTaskInBackground(
        new TestTask('Provision server'),
        'https://example.com/finished',
        'https://example.com/failed',
        'https://example.com/timeout',
    );

    $dispatcher = new class(app(ProcessRunner::class)) extends TaskDispatcher
    {
        public bool $usedRemoteDispatch = false;

        public bool $usedLocalBackground = false;

        public function runOnConnection(PendingTask $pendingTask): ProcessOutput
        {
            $this->usedRemoteDispatch = true;

            return ProcessOutput::make('remote started')->setExitCode(0);
        }

        public function runInBackground(PendingTask $pendingTask): ProcessOutput
        {
            $this->usedLocalBackground = true;

            return ProcessOutput::make('local background started')->setExitCode(0);
        }

        public function startBackgroundMonitoring(Task $task, TaskModel $taskModel): void {}
    };
    $dispatcher->runInBackgroundWithModel($trackedTask, $taskModel);

    $this->assertTrue($dispatcher->usedRemoteDispatch);
    $this->assertFalse($dispatcher->usedLocalBackground);
});
