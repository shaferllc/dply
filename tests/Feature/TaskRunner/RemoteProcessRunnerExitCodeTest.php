<?php

declare(strict_types=1);

namespace Tests\Feature\TaskRunner\RemoteProcessRunnerExitCodeTest;

use App\Modules\TaskRunner\Connection;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\ProcessRunner;
use App\Modules\TaskRunner\RemoteProcessRunner;

function capturingRunner(): object
{
    $connection = new Connection(
        host: '127.0.0.1',
        port: 22,
        username: 'root',
        privateKey: 'fake',
        scriptPath: '/root/.dply-task-runner',
        proxyJump: null,
    );

    return new class($connection, app(ProcessRunner::class)) extends RemoteProcessRunner
    {
        public ?string $capturedScript = null;

        public function run(string $script, int $timeout = 0): ProcessOutput
        {
            $this->capturedScript = $script;

            return ProcessOutput::make('ok')->setExitCode(0);
        }
    };
}

test('run uploaded script re-raises the script exit status past tee', function () {
    // Regression: `bash script | tee log` reports TEE's exit status (0) no matter
    // how the script died, so every synchronous remote task looked successful —
    // failed cache/database installs were recorded as running, failed lifecycle
    // actions as done. The PIPESTATUS re-raise makes the script's own status the
    // command's status again.
    $runner = capturingRunner();

    $runner->runUploadedScript('task-abc.sh', 'task-abc.log', 60);

    expect($runner->capturedScript)
        ->toContain('bash /root/.dply-task-runner/task-abc.sh 2>&1 | tee /root/.dply-task-runner/task-abc.log')
        ->toContain('exit ${PIPESTATUS[0]}');
});

test('run uploaded script still tees output for the streaming log readers', function () {
    $runner = capturingRunner();

    $runner->runUploadedScript('task-abc.sh', 'task-abc.log', 60);

    expect($runner->capturedScript)->toContain('| tee /root/.dply-task-runner/task-abc.log');
});
