<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Concerns\CreatesTaskRunnerBuilders;
use App\Modules\TaskRunner\Concerns\DispatchesTaskRunnerToServers;
use App\Modules\TaskRunner\Concerns\ManagesTaskRunnerFakes;
use App\Modules\TaskRunner\Concerns\RunsTaskRunnerParallelChains;
use App\Modules\TaskRunner\Concerns\RunsTaskRunnerTasks;
use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Contracts\TaskDispatcherInterface;
use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Events\TaskCompleted;
use App\Modules\TaskRunner\Events\TaskFailed;
use App\Modules\TaskRunner\Events\TaskStarted;
use App\Modules\TaskRunner\Exceptions\TaskExecutionException;
use App\Modules\TaskRunner\Jobs\ExecuteTaskJob;
use App\Modules\TaskRunner\Jobs\TaskTimeoutJob;
use App\Modules\TaskRunner\Jobs\UpdateTaskOutput;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\Traits\MakesTestAssertions;
use App\Modules\TaskRunner\Traits\PersistsFakeTasks;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process as FacadesProcess;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * TaskDispatcher that provides comprehensive task execution capabilities.
 * Combines functionality from both the original TaskDispatcher and EnhancedTaskDispatcher classes.
 */
class TaskDispatcher implements TaskDispatcherInterface
{
    use CreatesTaskRunnerBuilders;
    use DispatchesTaskRunnerToServers;
    use ManagesTaskRunnerFakes;
    use RunsTaskRunnerParallelChains;
    use RunsTaskRunnerTasks;

    use MakesTestAssertions, PersistsFakeTasks;

    private const DEFAULT_TIMEOUT = 10;

    private const SCRIPT_EXTENSION = '.sh';

    private const LOG_EXTENSION = '.log';

    /**
     * @var array<int, mixed>|bool
     */
    protected array|bool $tasksToFake = false;

    /**
     * @var array<int, mixed>
     */
    protected array $tasksToDispatch = [];

    /**
     * @var array<int, mixed>
     */
    protected array $dispatchedTasks = [];

    protected bool $preventStrayTasks = false;

    public function __construct(
        protected readonly ProcessRunner $processRunner,
        protected readonly ?int $defaultTimeout = null
    ) {}


}
