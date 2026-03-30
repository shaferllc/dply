<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Commands;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\ParallelTaskExecutor;
use App\Modules\TaskRunner\TaskChain;
use Illuminate\Console\Command;

class TaskRunCommand extends Command
{
    protected $signature = 'task:run 
                            {command : Command to run}
                            {--name= : Task name (defaults to command)}
                            {--timeout= : Timeout in seconds}
                            {--connection= : Connection to use}
                            {--parallel : Run in parallel mode}
                            {--max-concurrency=5 : Maximum concurrent tasks for parallel mode}
                            {--chain : Create a task chain}
                            {--view= : Use a Blade view instead of command}
                            {--data=* : Data to pass to view (key=value format)}
                            {--follow : Follow task output in real-time}
                            {--format=table : Output format (table, json)}
                            {--quiet : Suppress output}';

    protected $description = 'Run a task or command';

    public function handle(): int
    {
        $command = $this->argument('command');
        $name = $this->option('name') ?: $command;
        $timeout = $this->option('timeout');
        $connection = $this->option('connection');
        $parallel = $this->option('parallel');
        $maxConcurrency = (int) $this->option('max-concurrency');
        $chain = $this->option('chain');
        $view = $this->option('view');
        $data = $this->parseDataOption();
        $follow = $this->option('follow');
        $format = $this->option('format');
        $quiet = $this->option('quiet');

        try {
            if ($view) {
                return $this->runViewTask($view, $data, $name, $timeout, $connection, $follow, $format, $quiet);
            }

            if ($chain) {
                return $this->runTaskChain($command, $name, $timeout, $connection, $parallel, $maxConcurrency, $follow, $format, $quiet);
            }

            if ($parallel) {
                return $this->runParallelTask($command, $name, $timeout, $connection, $maxConcurrency, $follow, $format, $quiet);
            }

            return $this->runSingleTask($command, $name, $timeout, $connection, $follow, $format, $quiet);

        } catch (\Exception $e) {
            $this->error('Task execution failed: '.$e->getMessage());

            return 1;
        }
    }

    protected function runSingleTask(string $command, string $name, ?int $timeout, ?string $connection, bool $follow, string $format, bool $quiet): int
    {
        $task = AnonymousTask::command($name, $command);

        if ($timeout) {
            $task->timeout($timeout);
        }

        if ($connection) {
            $task->onConnection($connection);
        }

        if ($follow) {
            return $this->runAndFollow($task, $quiet);
        }

        $result = TaskRunner::run($task);

        if ($quiet) {
            return $result->isSuccessful() ? 0 : 1;
        }

        if ($format === 'json') {
            $this->outputJson($result, $task);
        } else {
            $this->outputTable($result, $task);
        }

        return $result->isSuccessful() ? 0 : 1;
    }

    protected function runParallelTask(string $command, string $name, ?int $timeout, ?string $connection, int $maxConcurrency, bool $follow, string $format, bool $quiet): int
    {
        $executor = ParallelTaskExecutor::make()
            ->withMaxConcurrency($maxConcurrency);

        if ($timeout) {
            $executor->withTimeout($timeout);
        }

        $task = AnonymousTask::command($name, $command);
        if ($connection) {
            $task->onConnection($connection);
        }

        $executor->add($task);

        if ($follow) {
            return $this->runParallelAndFollow($executor, $quiet);
        }

        $results = $executor->run();

        if ($quiet) {
            return $results['overall_success'] ? 0 : 1;
        }

        if ($format === 'json') {
            $this->outputJson($results, null, true);
        } else {
            $this->outputTable($results, null, true);
        }

        return $results['overall_success'] ? 0 : 1;
    }

    protected function runTaskChain(string $command, string $name, ?int $timeout, ?string $connection, bool $parallel, int $maxConcurrency, bool $follow, string $format, bool $quiet): int
    {
        $chain = TaskChain::make();

        if ($timeout) {
            $chain->withTimeout($timeout);
        }

        if ($parallel) {
            $chain->withParallel(true, $maxConcurrency);
        }

        // Split command by semicolon for multiple commands
        $commands = array_map('trim', explode(';', $command));

        foreach ($commands as $index => $cmd) {
            $taskName = count($commands) > 1 ? "{$name} - Step ".($index + 1) : $name;
            $chain->addCommand($taskName, $cmd);
        }

        if ($follow) {
            return $this->runChainAndFollow($chain, $quiet);
        }

        $results = $chain->run();

        if ($quiet) {
            return $results['overall_success'] ? 0 : 1;
        }

        if ($format === 'json') {
            $this->outputJson($results, null, false, true);
        } else {
            $this->outputTable($results, null, false, true);
        }

        return $results['overall_success'] ? 0 : 1;
    }

    protected function runViewTask(string $view, array $data, string $name, ?int $timeout, ?string $connection, bool $follow, string $format, bool $quiet): int
    {
        $task = AnonymousTask::view($name, $view, $data);

        if ($timeout) {
            $task->timeout($timeout);
        }

        if ($connection) {
            $task->onConnection($connection);
        }

        if ($follow) {
            return $this->runAndFollow($task, $quiet);
        }

        $result = TaskRunner::run($task);

        if ($quiet) {
            return $result->isSuccessful() ? 0 : 1;
        }

        if ($format === 'json') {
            $this->outputJson($result, $task);
        } else {
            $this->outputTable($result, $task);
        }

        return $result->isSuccessful() ? 0 : 1;
    }

    protected function runAndFollow($task, bool $quiet): int
    {
        if (! $quiet) {
            $this->info("Running task: {$task->getName()}");
            $this->line('Press Ctrl+C to stop');
            $this->newLine();
        }

        $result = TaskRunner::run($task, function (string $type, string $buffer) use ($quiet) {
            if (! $quiet) {
                if ($type === 'err') {
                    $this->error($buffer);
                } else {
                    $this->line($buffer);
                }
            }
        });

        if (! $quiet) {
            $this->newLine();
            $this->info("Task completed with exit code: {$result->getExitCode()}");
        }

        return $result->isSuccessful() ? 0 : 1;
    }

    protected function runParallelAndFollow(ParallelTaskExecutor $executor, bool $quiet): int
    {
        if (! $quiet) {
            $this->info('Running parallel task execution');
            $this->line('Press Ctrl+C to stop');
            $this->newLine();
        }

        $results = $executor->run();

        if (! $quiet) {
            $this->newLine();
            $this->info('Parallel execution completed');
            $this->line("Success rate: {$results['success_rate']}%");
        }

        return $results['overall_success'] ? 0 : 1;
    }

    protected function runChainAndFollow(TaskChain $chain, bool $quiet): int
    {
        if (! $quiet) {
            $this->info('Running task chain');
            $this->line('Press Ctrl+C to stop');
            $this->newLine();
        }

        $results = $chain->run();

        if (! $quiet) {
            $this->newLine();
            $this->info('Task chain completed');
            $this->line("Success rate: {$results['success_rate']}%");
        }

        return $results['overall_success'] ? 0 : 1;
    }

    protected function outputTable($result, $task = null, bool $isParallel = false, bool $isChain = false): void
    {
        if ($isParallel || $isChain) {
            $this->info('Execution Results');
            $this->line('================');

            $this->table([], [
                ['Total Tasks', $result['total_tasks']],
                ['Successful', $result['successful_tasks']],
                ['Failed', $result['failed_tasks']],
                ['Success Rate', $result['success_rate'].'%'],
                ['Duration', number_format($result['duration'], 2).'s'],
                ['Overall Success', $result['overall_success'] ? 'Yes' : 'No'],
            ]);

            if (isset($result['results']) && ! empty($result['results'])) {
                $this->newLine();
                $this->info('Individual Task Results');
                $this->line('=====================');

                $headers = ['Task', 'Status', 'Exit Code', 'Duration'];
                $rows = [];

                foreach ($result['results'] as $taskResult) {
                    $rows[] = [
                        $taskResult['task_name'],
                        $taskResult['success'] ? 'Success' : 'Failed',
                        $taskResult['exit_code'] ?? 'N/A',
                        number_format($taskResult['duration'], 2).'s',
                    ];
                }

                $this->table($headers, $rows);
            }
        } else {
            $this->info('Task Results');
            $this->line('============');

            $this->table([], [
                ['Name', $task->getName()],
                ['Status', $result->isSuccessful() ? 'Success' : 'Failed'],
                ['Exit Code', $result->getExitCode()],
                ['Duration', number_format($result->getDuration(), 2).'s'],
            ]);

            if ($result->getBuffer()) {
                $this->newLine();
                $this->info('Output');
                $this->line('======');
                $this->line($result->getBuffer());
            }
        }
    }

    protected function outputJson($result, $task = null, bool $isParallel = false, bool $isChain = false): void
    {
        if ($isParallel || $isChain) {
            $data = [
                'total_tasks' => $result['total_tasks'],
                'successful_tasks' => $result['successful_tasks'],
                'failed_tasks' => $result['failed_tasks'],
                'success_rate' => $result['success_rate'],
                'duration' => $result['duration'],
                'overall_success' => $result['overall_success'],
                'results' => $result['results'] ?? [],
            ];
        } else {
            $data = [
                'name' => $task->getName(),
                'successful' => $result->isSuccessful(),
                'exit_code' => $result->getExitCode(),
                'duration' => $result->getDuration(),
                'output' => $result->getBuffer(),
            ];
        }

        $this->output->write(json_encode($data, JSON_PRETTY_PRINT));
    }

    protected function parseDataOption(): array
    {
        $data = [];
        $dataOptions = $this->option('data');

        foreach ($dataOptions as $option) {
            if (str_contains($option, '=')) {
                [$key, $value] = explode('=', $option, 2);
                $data[$key] = $value;
            }
        }

        return $data;
    }
}
