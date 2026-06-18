<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Facades;

use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\TaskDispatcher;
use Illuminate\Support\Facades\Facade;

/**
 * @method static self assertDispatched(string|callable $taskClass, callable $additionalCallback = null)
 * @method static self assertDispatchedTimes(string|callable $taskClass, int $times = 1, callable $additionalCallback = null)
 * @method static self assertNotDispatched(string|callable $taskClass, callable $additionalCallback = null)
 * @method static self fake(array<int|string, string|callable>|string $tasksToFake = [])
 * @method static self dontFake(array<int|string, string|callable>|string $taskToDispatch)
 * @method static self preventStrayTasks(bool $prevent = true)
 * @method static \App\Modules\TaskRunner\AnonymousTask command(string $name, string $command, array<string, mixed> $options = [])
 * @method static \App\Modules\TaskRunner\AnonymousTask commands(string $name, array<int, string> $commands, array<string, mixed> $options = [])
 * @method static \App\Modules\TaskRunner\AnonymousTask view(string $name, string $view, array<string, mixed> $data = [], array<string, mixed> $options = [])
 * @method static \App\Modules\TaskRunner\AnonymousTask callback(string $name, \Closure $callback, array<string, mixed> $options = [])
 * @method static array<string, mixed> dispatchToMultipleServers(\App\Modules\TaskRunner\Task $task, array<int|string, mixed> $connections, array<string, mixed> $options = [])
 * @method static array<string, mixed> dispatchAnonymousToMultipleServers(\App\Modules\TaskRunner\AnonymousTask $task, array<int|string, mixed> $connections, array<string, mixed> $options = [])
 * @method static \App\Modules\TaskRunner\TaskChain chain()
 * @method static array<string, mixed> runChain(\App\Modules\TaskRunner\TaskChain $chain)
 * @method static array<string, mixed> runTaskChain(array<int, \App\Modules\TaskRunner\Task> $tasks, array<string, mixed> $options = [])
 * @method static ProcessOutput|null run(PendingTask $pendingTask)
 * @method static ProcessOutput|null runAnonymous(\App\Modules\TaskRunner\AnonymousTask $task)
 *
 * @see TaskDispatcher
 */
class TaskRunner extends Facade
{
    protected static function getFacadeAccessor()
    {
        return TaskDispatcher::class;
    }
}
