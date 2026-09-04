<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Commands;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\ParallelTaskExecutor;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\TaskChain;
use Illuminate\Console\Command;
use InvalidArgumentException;

class TaskRunCommand extends Command
{
    protected $signature = 'task:run 
                            {cmd? : Command to run (omit when using --task)}
                            {--task= : Re-run a stored task by id or name, instead of a new command}
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
                            {--background : Queue the task and return immediately}
                            {--dry-run : Report what would run, and run nothing}
                            {--instance= : Record the instance this run belongs to}
                            {--option=* : Extra task options (key=value, repeatable)}
                            {--force : Run even when the stored task is already running}
                            {--user= : System user to run the task as}
                            {--wait : Wait for completion before returning (default for a foreground run)}
                            {--show-output : Print the output when the task finishes}
                            {--no-output : Suppress output}';

    protected $description = 'Run a task by ID or name, or run a new command';

    public function handle(): int
    {
        // 'cmd', not 'command': Symfony's Application already defines a
        // 'command' argument (the command name itself), so declaring it threw
        // "An argument with name "command" already exists." on every invocation.
        // Re-running a recorded task is a distinct entry point: resolve it to
        // the script it ran and hand that to the ordinary execution path below,
        // so a re-run behaves exactly like the original invocation.
        $taskRefOption = $this->option('task');
        $taskRef = is_scalar($taskRefOption) ? trim((string) $taskRefOption) : '';
        $command = $this->argument('cmd');

        if ($taskRef !== '') {
            $ambiguous = false;
            $stored = $this->resolveStoredTask($taskRef, $ambiguous);

            if ($stored === null) {
                if (! $ambiguous) {
                    $this->error("Task not found: {$taskRef}");
                }

                return 1;
            }

            $command = $stored->script_content ?: $stored->script;

            if (! is_string($command) || trim($command) === '') {
                $this->error("Task [{$stored->name}] has no recorded command to re-run.");

                return 1;
            }

            // A terminal or in-flight task is not re-run by accident: --force
            // is what says "yes, run it again".
            if (! $this->option('force')) {
                if ($stored->status === TaskStatus::Running) {
                    $this->error('Task is already running. Pass --force to run it again.');

                    return 1;
                }

                if ($stored->status === TaskStatus::Finished) {
                    $this->error('Task is already finished. Pass --force to run it again.');

                    return 1;
                }
            }

            $this->info("Running task: {$stored->name}");
            $this->input->setOption('name', $this->option('name') ?: $stored->name);

            if ($stored->timeout && ! $this->option('timeout')) {
                $this->input->setOption('timeout', (string) $stored->timeout);
            }
        }

        if (! is_string($command) || trim($command) === '') {
            // Symfony's own phrasing: `cmd` is optional at the parser level
            // now that --task exists, so this is the check that replaces it.
            $this->error('Not enough arguments: provide a command, or --task=<id-or-name> to re-run a stored one.');

            return 1;
        }

        $name = $this->option('name') ?: $command;

        // Reject bad input before anything is created or run: an empty --user
        // or --instance would be recorded as a blank on the task, and a
        // non-numeric timeout silently became "no timeout".
        $rawTimeoutOption = $this->option('timeout');
        if ($rawTimeoutOption !== null && (! is_numeric($rawTimeoutOption) || (int) $rawTimeoutOption <= 0)) {
            $this->error('Timeout must be a positive integer');

            return 1;
        }

        foreach (['user' => 'User', 'instance' => 'Instance'] as $option => $label) {
            $value = $this->option($option);
            if ($value !== null && trim((string) $value) === '') {
                $this->error("{$label} cannot be empty");

                return 1;
            }
        }

        // Before anything is executed: a dry run reports and stops, and a
        // backgrounded run hands off without waiting for output.
        if ($this->option('dry-run')) {
            $this->info('Dry run mode - task would be executed');
            $this->line("Task: {$name}");
            $this->line("Command: {$command}");

            return 0;
        }

        if ($this->option('background')) {
            $queued = Task::create([
                'name' => $name,
                'script' => $command,
                'script_content' => $command,
                'status' => TaskStatus::Pending,
                'timeout' => is_numeric($this->option('timeout')) ? (int) $this->option('timeout') : null,
                'instance' => $this->option('instance'),
                'user' => $this->option('user'),
                'options' => $this->parseOptionPairs(),
            ]);

            $this->info('Task queued for background execution');
            $this->line("Task id: {$queued->id}");

            return 0;
        }
        $rawTimeout = $this->option('timeout');
        $timeout = is_string($rawTimeout) && $rawTimeout !== '' ? (int) $rawTimeout : null;
        $connection = $this->option('connection');
        $parallel = $this->option('parallel');
        $maxConcurrency = (int) $this->option('max-concurrency');
        $chain = $this->option('chain');
        $view = $this->option('view');
        $data = $this->parseDataOption();
        $follow = $this->option('follow');
        $format = $this->option('format');
        // 'no-output', not 'quiet': --quiet is a reserved Symfony global (and
        // --silent is Laravel's), so declaring it made every invocation throw
        // "An option named "quiet" already exists." before handle() ran.
        $quiet = $this->option('no-output');

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

    /**
     * Id first, then the most recent task with that name — the same resolution
     * `task:show` uses, so an identifier that works for one works for the other.
     */
    /** Wall-clock seconds for the run just executed, for the summary table. */
    protected ?float $lastRunSeconds = null;

    /**
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    protected function timed(callable $run): mixed
    {
        $startedAt = microtime(true);

        try {
            return $run();
        } finally {
            $this->lastRunSeconds = microtime(true) - $startedAt;
        }
    }

    /**
     * `--option key=value` pairs, same shape as the existing --data handling.
     *
     * @return array<string, string>
     */
    protected function parseOptionPairs(): array
    {
        $parsed = [];

        foreach ((array) $this->option('option') as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                throw new InvalidArgumentException('Invalid option format: '.(is_string($pair) ? $pair : get_debug_type($pair)));
            }
            [$key, $value] = explode('=', $pair, 2);
            $parsed[trim($key)] = $value;
        }

        return $parsed;
    }

    protected function resolveStoredTask(string $identifier, bool &$ambiguous = false): ?Task
    {
        $task = Task::find($identifier);

        if ($task) {
            return $task;
        }

        $byName = Task::where('name', $identifier)->latest()->get();

        if ($byName->count() > 1) {
            // Silently taking the newest would re-run a different task than the
            // operator named. Make them disambiguate with an id.
            $ambiguous = true;
            $this->error("Multiple tasks found with name: {$identifier}");
            $this->line('Re-run by id instead: '.$byName->take(3)->pluck('id')->implode(', '));

            return null;
        }

        return $byName->first();
    }

    protected function runSingleTask(string $command, string $name, ?int $timeout, ?string $connection, bool $follow, string $format, bool $quiet): int
    {
        $task = AnonymousTask::command($name, $command);

        if ($timeout) {
            $task->setTimeout($timeout);
        }

        if ($connection) {
            // AnonymousTask has no connection support (only PendingTask defines
            // onConnection()), so this used to fatal. Say so rather than
            // silently running against the default connection.
            $this->warn('--connection is not supported for ad-hoc tasks; running locally.');
        }

        if ($follow) {
            return $this->runAndFollow($task, $quiet);
        }

        $result = $this->timed(fn () => TaskRunner::run(new PendingTask($task)));

        if ($quiet) {
            return $result->isSuccessful() ? 0 : 1;
        }

        if ($format === 'json') {
            $this->outputJson($result, $task);
        } else {
            $this->outputTable($result, $task);

            if ($result->isSuccessful()) {
                $this->info('Task completed successfully');
            } else {
                $this->error('Task execution failed with exit code: '.($result->getExitCode() ?? 'unknown'));
            }
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
            // AnonymousTask has no connection support (only PendingTask defines
            // onConnection()), so this used to fatal. Say so rather than
            // silently running against the default connection.
            $this->warn('--connection is not supported for ad-hoc tasks; running locally.');
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
            $task->setTimeout($timeout);
        }

        if ($connection) {
            // AnonymousTask has no connection support (only PendingTask defines
            // onConnection()), so this used to fatal. Say so rather than
            // silently running against the default connection.
            $this->warn('--connection is not supported for ad-hoc tasks; running locally.');
        }

        if ($follow) {
            return $this->runAndFollow($task, $quiet);
        }

        $result = $this->timed(fn () => TaskRunner::run(new PendingTask($task)));

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

        // run() takes only the PendingTask; the follow callback is registered on
        // the task via onOutput() (run() picks it up through getOnOutput()).
        // Passing it as a second argument silently discarded it, so --follow
        // printed nothing.
        $result = $this->timed(fn () => TaskRunner::run($task->onOutput(function (string $type, string $buffer) use ($quiet) {
            if (! $quiet) {
                if ($type === 'err') {
                    $this->error($buffer);
                } else {
                    $this->line($buffer);
                }
            }
        })));

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
                ['Duration', number_format($this->lastRunSeconds ?? 0.0, 2).'s'],
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

    /** @return array<string, mixed> */
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
