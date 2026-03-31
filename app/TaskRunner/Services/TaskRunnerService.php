<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Services;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\TaskChain;
use App\Modules\TaskRunner\TaskDispatcher;
use Illuminate\Support\Collection;

/**
 * TaskRunner Service - Master Orchestrator
 *
 * Central service coordinating all task execution operations across specialized services.
 * Provides unified API for task creation, execution, monitoring, analytics, and lifecycle management.
 *
 * Used by:
 * - Task management UI (Livewire components)
 * - API endpoints (/api/v1/tasks/*)
 * - CLI commands (artisan task:*)
 * - Background job processing
 *
 * Integration points:
 * - Teams module: Team-scoped task execution
 * - Servers module: Remote task execution on servers
 * - ActivityFeed: Logs all task operations
 * - Notifications: Task completion alerts
 * - Usage module: Track task execution quotas
 * - Billing module: Plan-based task limits
 *
 * Architecture:
 * - Coordinates 8 specialized services
 * - Manages TaskDispatcher for execution
 * - Handles task chains and parallel execution
 * - Provides analytics and monitoring
 * - Supports rollback and error recovery
 *
 * @see TaskDispatcher
 * @see AnalyticsService
 * @see MonitoringService
 */
class TaskRunnerService
{
    public function __construct(
        protected TaskDispatcher $dispatcher,
        protected AnalyticsService $analytics,
        protected MonitoringService $monitoring,
        protected CallbackService $callback,
        protected RollbackService $rollback,
        protected TemplateService $template,
        protected BackgroundTaskTracker $tracker,
        protected TaskSchedulingService $scheduling
    ) {}

    /**
     * Create and execute task with full monitoring
     *
     * @param  string  $name  Task name
     * @param  string  $script  Script to execute
     * @param  array  $options  Execution options
     * @return array Execution result
     */
    public function createAndExecute(string $name, string $script, array $options = []): array
    {
        // Create task model
        $taskModel = TaskModel::create([
            'name' => $name,
            'script' => $script,
            'status' => TaskStatus::Pending,
            'timeout' => $options['timeout'] ?? 300,
            'user' => $options['user'] ?? 'root',
            'server_id' => $options['server_id'] ?? null,
            'created_by' => auth()->id(),
            'options' => $options,
        ]);

        // Execute task
        $isBackground = $options['background'] ?? false;

        if ($isBackground) {
            $result = $this->executeInBackground($taskModel);
        } else {
            $result = $this->executeSynchronous($taskModel);
        }

        // Record analytics
        $this->analytics->recordMetric($taskModel->id, 'execution_time', $result['duration'] ?? 0);

        // Send callbacks if configured
        if (isset($options['callback_url'])) {
            $this->callback->send($taskModel, CallbackType::OnComplete, $result);
        }

        return $result;
    }

    /**
     * Execute task synchronously
     *
     * @param  TaskModel  $taskModel  Task to execute
     * @return array Execution result
     */
    protected function executeSynchronous(TaskModel $taskModel): array
    {
        $startTime = microtime(true);

        // Create anonymous task
        $task = new class($taskModel->script) extends Task
        {
            public function __construct(protected string $scriptContent)
            {
                parent::__construct();
            }

            public function getScript(): string
            {
                return $this->scriptContent;
            }
        };

        // Execute via dispatcher
        $output = $this->dispatcher->runWithModel($task, $taskModel);

        $duration = microtime(true) - $startTime;

        return [
            'success' => $output->isSuccessful(),
            'output' => $output->getBuffer(),
            'exit_code' => $output->getExitCode(),
            'duration' => round($duration, 2),
            'task_id' => $taskModel->id,
        ];
    }

    /**
     * Execute task in background
     *
     * @param  TaskModel  $taskModel  Task to execute
     * @return array Execution result
     */
    protected function executeInBackground(TaskModel $taskModel): array
    {
        // Track in background
        $this->tracker->track($taskModel);

        return [
            'success' => true,
            'status' => 'dispatched',
            'task_id' => $taskModel->id,
            'message' => 'Task dispatched to background queue',
        ];
    }

    /**
     * Get task execution status with full details
     *
     * @param  string  $taskId  Task ID
     * @return array Task status and details
     */
    public function getTaskStatus(string $taskId): array
    {
        $task = TaskModel::find($taskId);

        if (! $task) {
            return [
                'found' => false,
                'error' => 'Task not found',
            ];
        }

        return [
            'found' => true,
            'task' => $task,
            'status' => $task->status->value,
            'progress' => $this->calculateProgress($task),
            'analytics' => $this->analytics->getTaskAnalytics($taskId),
            'monitoring' => $this->monitoring->getHealthStatus($taskId),
        ];
    }

    /**
     * Calculate task progress percentage
     *
     * @param  TaskModel  $task  Task to analyze
     * @return int Progress percentage
     */
    protected function calculateProgress(TaskModel $task): int
    {
        return match ($task->status) {
            TaskStatus::Pending => 0,
            TaskStatus::Running => 50,
            TaskStatus::Finished => 100,
            TaskStatus::Failed => 100,
            default => 0,
        };
    }

    /**
     * Create task chain with monitoring
     *
     * @param  array  $tasks  Array of tasks
     * @param  array  $options  Chain options
     * @return array Chain creation result
     */
    public function createTaskChain(array $tasks, array $options = []): array
    {
        $chain = new TaskChain;

        foreach ($tasks as $taskData) {
            $task = $this->createTaskFromData($taskData);
            $chain->add($task);
        }

        // Configure chain options
        if (isset($options['stop_on_failure'])) {
            if (! $options['stop_on_failure']) {
                $chain->dontStopOnFailure();
            }
        }

        return [
            'success' => true,
            'chain' => $chain,
            'task_count' => count($tasks),
        ];
    }

    /**
     * Create task from data array
     *
     * @param  array  $data  Task data
     * @return Task Task instance
     */
    protected function createTaskFromData(array $data): Task
    {
        return new class($data['script']) extends Task
        {
            public function __construct(protected string $scriptContent)
            {
                parent::__construct();
            }

            public function getScript(): string
            {
                return $this->scriptContent;
            }
        };
    }

    /**
     * Execute parallel tasks
     *
     * @param  array  $tasks  Tasks to execute in parallel
     * @param  array  $options  Execution options
     * @return array Execution results
     */
    public function executeParallel(array $tasks, array $options = []): array
    {
        $results = [];
        $successful = 0;
        $failed = 0;

        foreach ($tasks as $index => $taskData) {
            try {
                $result = $this->createAndExecute(
                    $taskData['name'] ?? "Parallel Task {$index}",
                    $taskData['script'],
                    array_merge($options, ['background' => true])
                );

                $results[] = $result;

                if ($result['success']) {
                    $successful++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => $failed === 0,
            'total' => count($tasks),
            'successful' => $successful,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * Get analytics summary for all tasks
     *
     * @param  array  $filters  Query filters
     * @return array Analytics summary
     */
    public function getAnalyticsSummary(array $filters = []): array
    {
        $query = TaskModel::query();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['created_after'])) {
            $query->where('created_at', '>=', $filters['created_after']);
        }

        $tasks = $query->get();

        return [
            'total_tasks' => $tasks->count(),
            'by_status' => $this->groupTasksByStatus($tasks),
            'avg_duration' => $this->calculateAverageDuration($tasks),
            'success_rate' => $this->calculateSuccessRate($tasks),
        ];
    }

    /**
     * Group tasks by status
     *
     * @param  Collection  $tasks  Tasks to group
     * @return array Status groups
     */
    protected function groupTasksByStatus(Collection $tasks): array
    {
        return $tasks->groupBy(fn ($t) => $t->status->value)->map->count()->toArray();
    }

    /**
     * Calculate average task duration
     *
     * @param  Collection  $tasks  Tasks to analyze
     * @return float Average duration in seconds
     */
    protected function calculateAverageDuration(Collection $tasks): float
    {
        $completed = $tasks->filter(fn ($t) => $t->completed_at && $t->started_at);

        if ($completed->isEmpty()) {
            return 0.0;
        }

        $totalDuration = $completed->sum(fn ($t) => $t->started_at->diffInSeconds($t->completed_at));

        return round($totalDuration / $completed->count(), 2);
    }

    /**
     * Calculate success rate
     *
     * @param  Collection  $tasks  Tasks to analyze
     * @return float Success rate percentage
     */
    protected function calculateSuccessRate(Collection $tasks): float
    {
        if ($tasks->isEmpty()) {
            return 0.0;
        }

        $successful = $tasks->where('status', TaskStatus::Finished)->count();

        return round(($successful / $tasks->count()) * 100, 2);
    }

    /**
     * Retry failed task
     *
     * @param  string  $taskId  Task ID to retry
     * @param  array  $options  Retry options
     * @return array Retry result
     */
    public function retryTask(string $taskId, array $options = []): array
    {
        $task = TaskModel::find($taskId);

        if (! $task) {
            return [
                'success' => false,
                'error' => 'Task not found',
            ];
        }

        if ($task->status !== TaskStatus::Failed) {
            return [
                'success' => false,
                'error' => 'Only failed tasks can be retried',
            ];
        }

        // Create new task from failed task
        return $this->createAndExecute(
            $task->name.' (Retry)',
            $task->script,
            array_merge($task->options ?? [], $options)
        );
    }

    /**
     * Cancel running task
     *
     * @param  string  $taskId  Task ID to cancel
     * @return array Cancellation result
     */
    public function cancelTask(string $taskId): array
    {
        $task = TaskModel::find($taskId);

        if (! $task) {
            return [
                'success' => false,
                'error' => 'Task not found',
            ];
        }

        if ($task->status !== TaskStatus::Running) {
            return [
                'success' => false,
                'error' => 'Only running tasks can be cancelled',
            ];
        }

        $task->refresh();

        if ($task->server) {
            $remoteCancellation = $this->cancelRemoteTaskExecution($task);

            if (! $remoteCancellation['success']) {
                return $remoteCancellation;
            }
        }

        $task->update([
            'status' => TaskStatus::Cancelled,
            'output' => $this->appendCancellationMessage($task->output),
            'completed_at' => now(),
        ]);

        return [
            'success' => true,
            'task' => $task->fresh(),
        ];
    }

    /**
     * @return array{success:bool,error?:string}
     */
    private function cancelRemoteTaskExecution(TaskModel $task): array
    {
        if (! $task->server) {
            return ['success' => true];
        }

        $script = $this->buildRemoteCancellationScript($task);
        if ($script === null) {
            return ['success' => true];
        }

        $output = $this->dispatcher->run(
            AnonymousTask::command('Cancel Task Process', $script)
                ->pending()
                ->onConnection($task->server->connectionAsRoot())
        );

        if (! $output || ! $output->isSuccessful()) {
            return [
                'success' => false,
                'error' => 'Could not cancel the remote task process.',
            ];
        }

        return ['success' => true];
    }

    private function appendCancellationMessage(?string $output): string
    {
        $existingOutput = trim((string) $output);

        if ($existingOutput === '') {
            return 'Task cancelled by user';
        }

        return $existingOutput."\nTask cancelled by user";
    }

    private function buildRemoteCancellationScript(TaskModel $task): ?string
    {
        $options = is_array($task->options) ? $task->options : [];

        $wrapperScriptPath = trim((string) ($options['remote_wrapper_script_path'] ?? $options['remote_script_path'] ?? ''));
        $actualScriptPath = trim((string) ($options['remote_script_path'] ?? ''));
        $wrapperPidPath = trim((string) ($options['remote_pid_path'] ?? ''));
        $childPidPath = trim((string) ($options['remote_child_pid_path'] ?? ''));

        if ($wrapperScriptPath === '' && $actualScriptPath === '' && $wrapperPidPath === '' && $childPidPath === '') {
            return null;
        }

        $quotedWrapperScriptPath = escapeshellarg($wrapperScriptPath);
        $quotedActualScriptPath = escapeshellarg($actualScriptPath);
        $quotedWrapperPidPath = escapeshellarg($wrapperPidPath);
        $quotedChildPidPath = escapeshellarg($childPidPath);

        return strtr(<<<'BASH'
set -eu

current_pid=$$

collect_pid() {
    pid_file="$1"

    if [ -n "$pid_file" ] && [ -f "$pid_file" ]; then
        pid=$(cat "$pid_file" 2>/dev/null || true)
        if [ -n "$pid" ]; then
            printf '%s\n' "$pid"
        fi
    fi
}

collect_matching_pids() {
    path="$1"

    if [ -z "$path" ]; then
        return
    fi

    ps -eo pid=,command= | awk -v path="$path" -v current_pid="$current_pid" 'index($0, path) > 0 && $1 != current_pid { print $1 }'
}

pids=$(
    {
        collect_pid __CHILD_PID_PATH__
        collect_pid __WRAPPER_PID_PATH__
        collect_matching_pids __ACTUAL_SCRIPT_PATH__
        collect_matching_pids __WRAPPER_SCRIPT_PATH__
    } | awk 'NF && !seen[$1]++'
)

if [ -z "$pids" ]; then
    exit 0
fi

for pid in $pids; do
    kill -TERM "$pid" 2>/dev/null || true
done

sleep 2

for pid in $pids; do
    if kill -0 "$pid" 2>/dev/null; then
        kill -KILL "$pid" 2>/dev/null || true
    fi
done

rm -f __CHILD_PID_PATH__ __WRAPPER_PID_PATH__
BASH, [
            '__CHILD_PID_PATH__' => $quotedChildPidPath,
            '__WRAPPER_PID_PATH__' => $quotedWrapperPidPath,
            '__ACTUAL_SCRIPT_PATH__' => $quotedActualScriptPath,
            '__WRAPPER_SCRIPT_PATH__' => $quotedWrapperScriptPath,
        ]);
    }

    /**
     * Get task history with filters
     *
     * @param  array  $filters  Query filters
     * @return Collection Task history
     */
    public function getTaskHistory(array $filters = []): Collection
    {
        $query = TaskModel::query()
            ->with(['server', 'creator'])
            ->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['server_id'])) {
            $query->where('server_id', $filters['server_id']);
        }

        if (isset($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        if (isset($filters['limit'])) {
            $query->limit($filters['limit']);
        }

        return $query->get();
    }

    /**
     * Get monitoring dashboard data
     *
     * @param  array  $filters  Query filters
     * @return array Dashboard data
     */
    public function getMonitoringDashboard(array $filters = []): array
    {
        $tasks = $this->getTaskHistory($filters);

        return [
            'summary' => $this->getAnalyticsSummary($filters),
            'recent_tasks' => $tasks->take(10),
            'running_tasks' => $tasks->where('status', TaskStatus::Running),
            'failed_tasks' => $tasks->where('status', TaskStatus::Failed)->take(5),
            'health_metrics' => $this->monitoring->getHealthMetrics(),
        ];
    }

    /**
     * Cleanup old completed tasks
     *
     * @param  int  $olderThanDays  Days threshold
     * @return array Cleanup result
     */
    public function cleanupOldTasks(int $olderThanDays = 30): array
    {
        $threshold = now()->subDays($olderThanDays);

        $deleted = TaskModel::where('status', TaskStatus::Finished)
            ->where('completed_at', '<', $threshold)
            ->delete();

        return [
            'success' => true,
            'deleted_count' => $deleted,
            'threshold_days' => $olderThanDays,
        ];
    }

    /**
     * Execute task with template
     *
     * @param  string  $templateName  Template name
     * @param  array  $variables  Template variables
     * @param  array  $options  Execution options
     * @return array Execution result
     */
    public function executeWithTemplate(string $templateName, array $variables, array $options = []): array
    {
        // Render template
        $script = $this->template->render($templateName, $variables);

        if (! $script) {
            return [
                'success' => false,
                'error' => 'Template not found or rendering failed',
            ];
        }

        // Execute rendered script
        return $this->createAndExecute(
            "Template: {$templateName}",
            $script,
            $options
        );
    }

    /**
     * Execute task with rollback support
     *
     * @param  string  $name  Task name
     * @param  string  $script  Forward script
     * @param  string  $rollbackScript  Rollback script
     * @param  array  $options  Execution options
     * @return array Execution result with rollback capability
     */
    public function executeWithRollback(string $name, string $script, string $rollbackScript, array $options = []): array
    {
        $result = $this->createAndExecute($name, $script, $options);

        // Register rollback if task succeeded
        if ($result['success']) {
            $this->rollback->registerRollback(
                $result['task_id'],
                $rollbackScript,
                $options['rollback_options'] ?? []
            );
        }

        return $result;
    }

    /**
     * Perform rollback for task
     *
     * @param  string  $taskId  Task ID to rollback
     * @return array Rollback result
     */
    public function performRollback(string $taskId): array
    {
        try {
            $result = $this->rollback->execute($taskId);

            return [
                'success' => true,
                'rollback_result' => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get task templates list
     *
     * @param  array  $filters  Template filters
     * @return array Templates list
     */
    public function getTemplates(array $filters = []): array
    {
        return $this->template->list($filters);
    }

    /**
     * Create task from template
     *
     * @param  string  $templateName  Template name
     * @param  array  $variables  Variables to substitute
     * @return array Task creation result
     */
    public function createFromTemplate(string $templateName, array $variables): array
    {
        $script = $this->template->render($templateName, $variables);

        if (! $script) {
            return [
                'success' => false,
                'error' => 'Template not found',
            ];
        }

        $taskModel = TaskModel::create([
            'name' => "From template: {$templateName}",
            'script' => $script,
            'status' => TaskStatus::Pending,
            'options' => ['template' => $templateName, 'variables' => $variables],
        ]);

        return [
            'success' => true,
            'task' => $taskModel,
            'script' => $script,
        ];
    }

    /**
     * Get performance insights
     *
     * @param  array  $filters  Query filters
     * @return array Performance insights
     */
    public function getPerformanceInsights(array $filters = []): array
    {
        $summary = $this->getAnalyticsSummary($filters);

        $insights = [];

        // Success rate insights
        if ($summary['success_rate'] < 70) {
            $insights[] = [
                'type' => 'warning',
                'category' => 'reliability',
                'message' => 'Task success rate is below 70% - review failure patterns',
            ];
        }

        // Duration insights
        if ($summary['avg_duration'] > 300) {
            $insights[] = [
                'type' => 'info',
                'category' => 'performance',
                'message' => 'Average task duration exceeds 5 minutes - consider optimization',
            ];
        }

        return [
            'summary' => $summary,
            'insights' => $insights,
        ];
    }

    /**
     * Export task execution report
     *
     * @param  array  $filters  Report filters
     * @param  string  $format  Export format (array, json, csv)
     * @return array|string Export data
     */
    public function exportReport(array $filters = [], string $format = 'array'): array|string
    {
        $tasks = $this->getTaskHistory($filters);
        $summary = $this->getAnalyticsSummary($filters);

        $data = [
            'summary' => $summary,
            'tasks' => $tasks->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'status' => $t->status->value,
                'duration' => $t->started_at && $t->completed_at
                    ? $t->started_at->diffInSeconds($t->completed_at)
                    : null,
                'created_at' => $t->created_at->toISOString(),
            ])->toArray(),
            'generated_at' => now()->toISOString(),
        ];

        if ($format === 'json') {
            return json_encode($data, JSON_PRETTY_PRINT);
        }

        if ($format === 'csv') {
            return $this->convertToCsv($data);
        }

        return $data;
    }

    /**
     * Convert report to CSV
     *
     * @param  array  $data  Report data
     * @return string CSV content
     */
    protected function convertToCsv(array $data): string
    {
        $csv = "Task Execution Report\n";
        $csv .= "Generated: {$data['generated_at']}\n\n";
        $csv .= "Summary\n";
        $csv .= "Total Tasks,{$data['summary']['total_tasks']}\n";
        $csv .= "Success Rate,{$data['summary']['success_rate']}%\n";
        $csv .= "Avg Duration,{$data['summary']['avg_duration']}s\n\n";
        $csv .= "Task,Status,Duration,Created\n";

        foreach ($data['tasks'] as $task) {
            $csv .= "\"{$task['name']}\",{$task['status']},{$task['duration']},{$task['created_at']}\n";
        }

        return $csv;
    }

    /**
     * Get task scheduling calendar
     *
     * @param  string  $month  Month to display (Y-m format)
     * @return array Calendar data
     */
    public function getSchedulingCalendar(string $month): array
    {
        return $this->scheduling->getCalendarView($month);
    }

    /**
     * Bulk task operations
     *
     * @param  array  $taskIds  Task IDs to operate on
     * @param  string  $operation  Operation (cancel, retry, delete)
     * @return array Operation results
     */
    public function bulkOperation(array $taskIds, string $operation): array
    {
        $results = [];
        $successful = 0;
        $failed = 0;

        foreach ($taskIds as $taskId) {
            try {
                $result = match ($operation) {
                    'cancel' => $this->cancelTask($taskId),
                    'retry' => $this->retryTask($taskId),
                    'delete' => $this->deleteTask($taskId),
                    default => ['success' => false, 'error' => 'Unknown operation'],
                };

                $results[$taskId] = $result;

                if ($result['success']) {
                    $successful++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $results[$taskId] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => $failed === 0,
            'operation' => $operation,
            'total' => count($taskIds),
            'successful' => $successful,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * Delete task
     *
     * @param  string  $taskId  Task ID to delete
     * @return array Deletion result
     */
    protected function deleteTask(string $taskId): array
    {
        $task = TaskModel::find($taskId);

        if (! $task) {
            return [
                'success' => false,
                'error' => 'Task not found',
            ];
        }

        $task->delete();

        return [
            'success' => true,
            'task_id' => $taskId,
        ];
    }

    /**
     * Get quick stats summary
     *
     * @return array Quick statistics
     */
    public function getQuickStats(): array
    {
        return [
            'total' => TaskModel::count(),
            'running' => TaskModel::where('status', TaskStatus::Running)->count(),
            'finished' => TaskModel::where('status', TaskStatus::Finished)->count(),
            'failed' => TaskModel::where('status', TaskStatus::Failed)->count(),
            'pending' => TaskModel::where('status', TaskStatus::Pending)->count(),
        ];
    }
}
