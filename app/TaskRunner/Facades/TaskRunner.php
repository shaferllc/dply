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
 * @method static self fake(array|string $tasksToFake = [])
 * @method static self dontFake(array|string $taskToDispatch)
 * @method static self preventStrayTasks(bool $prevent = true)
 * @method static ProcessOutput|null run(PendingTask $pendingTask)
 * @method static ProcessOutput|null runAnonymous(\App\Modules\TaskRunner\AnonymousTask $task)
 * @method static \App\Modules\TaskRunner\AnonymousTask anonymous(string $name, string $script, array $options = [])
 * @method static \App\Modules\TaskRunner\AnonymousTask command(string $name, string $command, array $options = [])
 * @method static \App\Modules\TaskRunner\AnonymousTask commands(string $name, array $commands, array $options = [])
 * @method static \App\Modules\TaskRunner\AnonymousTask view(string $name, string $view, array $data = [], array $options = [])
 * @method static \App\Modules\TaskRunner\AnonymousTask callback(string $name, \Closure $callback, array $options = [])
 * @method static array dispatchToMultipleServers(\App\Modules\TaskRunner\Task $task, array $connections, array $options = [])
 * @method static array dispatchAnonymousToMultipleServers(\App\Modules\TaskRunner\AnonymousTask $task, array $connections, array $options = [])
 * @method static \App\Modules\TaskRunner\TaskChain chain()
 * @method static array runChain(\App\Modules\TaskRunner\TaskChain $chain)
 * @method static array runTaskChain(array $tasks, array $options = [])
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
